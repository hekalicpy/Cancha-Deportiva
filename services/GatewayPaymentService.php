<?php
final class GatewayPaymentService {
    public function __construct(private mysqli $db) {}

    public function qr(int $reservationId): array {
        if (!TaypiGateway::configured()) throw new ApiError('La pasarela todavía no está configurada.', 503);
        $row = query($this->db,
            'SELECT p.id,p.metodo,p.monto,p.estado,r.estado AS reserva_estado,r.hold_until FROM pagos p JOIN reservas r ON r.id=p.reserva_id WHERE r.id=?',
            [$reservationId])->get_result()->fetch_assoc();
        if (!$row || $row['metodo'] !== 'Pasarela QR') throw new ApiError('Cobro de pasarela no encontrado.', 404);
        if ($row['estado'] !== 'Pendiente' || $row['reserva_estado'] !== 'Pendiente'
            || strtotime($row['hold_until']) <= time()) throw new ApiError('La reserva ya no admite un nuevo pago.', 409);
        $amount = number_format((float)$row['monto'], 2, '.', '');
        $reference = 'ARENA7-'.$row['id'];
        query($this->db,
            "INSERT IGNORE INTO pasarela_pagos (pago_id,referencia,estado) VALUES (?,?,'Pendiente')",
            [$row['id'], $reference]);
        $attempt = query($this->db,'SELECT * FROM pasarela_pagos WHERE pago_id=?',[$row['id']])->get_result()->fetch_assoc();
        if (!$attempt || $attempt['referencia'] !== $reference) throw new ApiError('No se pudo preparar el cobro.', 409);
        if ($attempt['estado'] !== 'Pendiente') throw new ApiError('Este cobro ya fue procesado.', 409);
        if ($attempt['qr_imagen']) return ['qr_image'=>$attempt['qr_imagen'], 'monto'=>$amount, 'modo'=>TaypiGateway::mode()];

        // A stable reference is also the provider idempotency key; retries cannot create another charge.
        $payment = (new TaypiGateway())->createPayment($amount, $reference);
        transaction($this->db, function () use ($row, $payment) {
            $attempt = query($this->db,'SELECT * FROM pasarela_pagos WHERE pago_id=? FOR UPDATE',[$row['id']])->get_result()->fetch_assoc();
            if ($attempt['id_proveedor'] && $attempt['id_proveedor'] !== $payment['payment_id']) {
                throw new ApiError('La pasarela respondió con un cobro distinto.', 409);
            }
            if ($attempt['estado'] === 'Pendiente') {
                query($this->db,'UPDATE pasarela_pagos SET id_proveedor=?,qr_imagen=? WHERE pago_id=?',
                    [$payment['payment_id'], $payment['qr_image'], $row['id']]);
            }
        });
        return ['qr_image'=>$payment['qr_image'], 'monto'=>$amount, 'modo'=>TaypiGateway::mode()];
    }

    public function webhook(string $body, string $signature, string $timestamp): array {
        if (!TaypiGateway::verifyWebhook($body, $signature, $timestamp)) throw new ApiError('Firma de pasarela inválida.', 401);
        $event = json_decode($body, true);
        if (!is_array($event) || !in_array($event['event'] ?? '', ['payment.completed','payment.expired'], true)) {
            return ['ok'=>true];
        }
        $reference = $event['reference'] ?? '';
        $providerId = $event['payment_id'] ?? '';
        if (!is_string($reference) || !preg_match('/^ARENA7-[0-9]+$/', $reference)
            || !is_string($providerId) || !preg_match('/^[A-Za-z0-9-]{10,100}$/', $providerId)) {
            throw new ApiError('Identificador de pago inválido.', 400);
        }
        return transaction($this->db, function () use ($event, $reference, $providerId) {
            $attempt = query($this->db,'SELECT * FROM pasarela_pagos WHERE referencia=? FOR UPDATE',[$reference])->get_result()->fetch_assoc();
            if (!$attempt) throw new ApiError('Cobro de pasarela no encontrado.', 404);
            $payment = query($this->db,'SELECT * FROM pagos WHERE id=? FOR UPDATE',[$attempt['pago_id']])->get_result()->fetch_assoc();
            $reservation = query($this->db,'SELECT * FROM reservas WHERE id=? FOR UPDATE',[$payment['reserva_id']])->get_result()->fetch_assoc();
            if ($payment['metodo'] !== 'Pasarela QR' || ($attempt['id_proveedor'] && $attempt['id_proveedor'] !== $providerId)
                || (string)($event['currency'] ?? '') !== 'PEN'
                || !preg_match('/^[0-9]+\.[0-9]{2}$/', (string)($event['amount'] ?? ''))
                || (string)$event['amount'] !== number_format((float)$payment['monto'], 2, '.', '')) {
                throw new ApiError('El cobro recibido no coincide con la reserva.', 409);
            }
            if (!$attempt['id_proveedor']) query($this->db,'UPDATE pasarela_pagos SET id_proveedor=? WHERE id=?',[$providerId,$attempt['id']]);
            if ($event['event'] === 'payment.expired') {
                if ($attempt['estado'] === 'Pendiente') query($this->db,"UPDATE pasarela_pagos SET estado='Vencido' WHERE id=?",[$attempt['id']]);
                return ['ok'=>true];
            }
            if (($event['status'] ?? '') !== 'completed') throw new ApiError('Estado de pago inválido.', 409);
            if (in_array($attempt['estado'], ['Confirmado','Revisar'], true)) return ['ok'=>true];
            $actor = 'TAYPI';
            if ($payment['estado'] !== 'Pendiente' || $reservation['estado'] !== 'Pendiente'
                || strtotime($reservation['hold_until']) <= time()) {
                query($this->db,"UPDATE pasarela_pagos SET estado='Revisar' WHERE id=?",[$attempt['id']]);
                (new AuditService($this->db))->record($actor,'PAGO_PASARELA_TARDIO','pagos',(int)$payment['id'],
                    'Cobro recibido después del vencimiento o cancelación; requiere revisión y posible devolución.');
                return ['ok'=>true,'revision'=>true];
            }
            query($this->db,"UPDATE pasarela_pagos SET estado='Confirmado' WHERE id=?",[$attempt['id']]);
            query($this->db,"UPDATE pagos SET estado='Confirmado',codigo=?,usuario=? WHERE id=?",[$providerId,$actor,$payment['id']]);
            query($this->db,"UPDATE reservas SET estado='Confirmado',hold_until=NULL WHERE id=?",[$reservation['id']]);
            query($this->db,"UPDATE notificaciones SET estado='Cancelado' WHERE reserva_id=? AND evento='Recibida' AND estado IN ('Pendiente','Error')",[$reservation['id']]);
            (new NotificationService($this->db))->enqueue((int)$reservation['id']);
            (new AuditService($this->db))->record($actor,'PAGO_CONFIRMAR_PASARELA','pagos',(int)$payment['id'],
                'Pago verificado por webhook firmado.');
            (new AuditService($this->db))->record($actor,'RESERVA_CONFIRMAR','reservas',(int)$reservation['id']);
            return ['ok'=>true];
        });
    }
}
