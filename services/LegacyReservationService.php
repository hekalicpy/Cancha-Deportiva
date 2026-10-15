<?php
class LegacyReservationService {
    public function __construct(private mysqli $db) {}

    private function fields(array $input): array {
        foreach (['dni', 'cliente', 'telefono', 'cancha', 'fecha', 'hora', 'pago', 'monto'] as $field) {
            if (trim((string)($input[$field] ?? '')) === '') throw new ApiError("Complete el campo $field.");
        }
        $dni = trim((string)$input['dni']);
        $cliente = trim((string)$input['cliente']);
        $telefono = trim((string)$input['telefono']);
        $cancha = (string)$input['cancha'];
        $fecha = (string)$input['fecha'];
        $hora = (string)$input['hora'];
        $pago = (string)$input['pago'];
        $codigo = trim((string)($input['codigoPago'] ?? ''));
        $detalle = trim((string)($input['detalle'] ?? ''));
        $monto = $pago === 'Pasarela QR' ? TaypiGateway::amount() : (string)$input['monto'];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if (!preg_match('/^\d{8}$/', $dni) || mb_strlen($cliente) > 150 || !preg_match('/^[+0-9 ()-]{9,20}$/', $telefono)
            || !in_array($cancha, ['1','2','3','4'], true)
            || !in_array($hora, ['08','09','10','11','12','13','14','15','16','18','19','20','21'], true)
            || !in_array($pago, ['Yape','Plin','Efectivo','Pasarela QR'], true)
            || !$date || $date->format('Y-m-d') !== $fecha || $fecha < date('Y-m-d')
            || !is_numeric($monto) || (float)$monto <= 0 || (float)$monto > 999999.99
            || mb_strlen($codigo) > 100 || mb_strlen($detalle) > 2000) {
            throw new ApiError('Revise los datos de la reserva.');
        }
        if ($pago === 'Pasarela QR' && !TaypiGateway::configured()) {
            throw new ApiError('La pasarela todavía no está configurada.', 503);
        }
        if ($pago === 'Pasarela QR' && $codigo !== '') throw new ApiError('El código de la pasarela lo genera el proveedor.');
        return [$dni, $cliente, $telefono, $cancha, $fecha, $hora, $pago, $codigo, $monto, $detalle];
    }

    private function lock(): void {
        if (!query($this->db, "SELECT GET_LOCK('arena7_reservas',5) AS locked")->get_result()->fetch_assoc()['locked']) {
            throw new ApiError('Sistema ocupado, vuelva a intentar.', 409);
        }
    }

    private function available(array $fields, ?int $id = null): void {
        $busy = query($this->db,
            "SELECT id FROM reservas WHERE cancha=? AND fecha=? AND hora=? AND id<>? AND (estado='Confirmado' OR (estado='Pendiente' AND hold_until>NOW())) LIMIT 1",
            [$fields[3], $fields[4], $fields[5], $id ?? 0]
        )->get_result()->fetch_assoc();
        if ($busy) throw new ApiError('La cancha ya está reservada en ese horario.', 409);
    }

    public function create(array $input, array $user): int {
        $fields = $this->fields($input);
        $identity = $_SESSION['identity'] ?? [];
        $source = ($identity['dni'] ?? null) === $fields[0] && ($identity['nombre'] ?? null) === $fields[1]
            && ($identity['expires'] ?? 0) >= time() ? 'Consulta API' : 'Manual';
        $this->lock();
        try {
            return transaction($this->db, function () use ($fields, $user, $source) {
                $this->available($fields);
                query($this->db,
                    'INSERT INTO reservas (dni,cliente,telefono,cancha,fecha,hora,pago,codigo_pago,monto,detalle,usuario,identidad) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [...$fields, $user['correo'], $source]
                );
                $id = $this->db->insert_id;
                if (in_array($fields[6], ['Yape','Plin','Pasarela QR'], true)) {
                    query($this->db, "UPDATE reservas SET estado='Pendiente',hold_until=DATE_ADD(NOW(),INTERVAL 15 MINUTE) WHERE id=?", [$id]);
                    (new PaymentService($this->db))->register($id, [
                        'pago'=>$fields[6], 'codigoPago'=>$fields[7], 'monto'=>$fields[8]
                    ], $user);
                }
                (new AuditService($this->db))->record($user['correo'], 'RESERVA_CREAR', 'reservas', $id,
                    json_encode(['cancha'=>$fields[3], 'fecha'=>$fields[4], 'hora'=>$fields[5]], JSON_UNESCAPED_UNICODE));
                return $id;
            });
        } finally {
            query($this->db, "SELECT RELEASE_LOCK('arena7_reservas')");
        }
    }

    public function edit(int $id, array $input, array $user): void {
        $fields = $this->fields($input);
        $identity = $_SESSION['identity'] ?? [];
        $source = ($identity['dni'] ?? null) === $fields[0] && ($identity['nombre'] ?? null) === $fields[1]
            && ($identity['expires'] ?? 0) >= time() ? 'Consulta API' : 'Manual';
        $this->lock();
        try {
            transaction($this->db, function () use ($id, $fields, $user, $source) {
                $old = query($this->db, 'SELECT * FROM reservas WHERE id=? FOR UPDATE', [$id])->get_result()->fetch_assoc();
                if (!$old || $old['estado'] === 'Cancelado') throw new ApiError('Reserva no encontrada.', 404);
                if ($old['estado'] === 'Pendiente' && $old['hold_until'] && strtotime($old['hold_until']) < time()) {
                    throw new ApiError('Esta reserva ya venció y no se puede editar.', 409);
                }
                $payment = query($this->db, 'SELECT id FROM pagos WHERE reserva_id=? LIMIT 1', [$id])->get_result()->fetch_assoc();
                if ($payment && ($old['pago'] !== $fields[6] || $old['codigo_pago'] !== $fields[7]
                    || (float)$old['monto'] !== (float)$fields[8])) {
                    throw new ApiError('El pago registrado no puede alterarse desde la edición de reserva.');
                }
                $this->available($fields, $id);
                query($this->db,
                    'UPDATE reservas SET dni=?,cliente=?,telefono=?,cancha=?,fecha=?,hora=?,pago=?,codigo_pago=?,monto=?,detalle=?,usuario=?,identidad=? WHERE id=?',
                    [...$fields, $user['correo'], $source, $id]
                );
                $before = array_intersect_key($old, array_flip(['cancha', 'fecha', 'hora', 'pago', 'monto', 'estado']));
                $after = ['cancha'=>$fields[3], 'fecha'=>$fields[4], 'hora'=>$fields[5], 'pago'=>$fields[6], 'monto'=>$fields[8]];
                (new AuditService($this->db))->record($user['correo'], 'RESERVA_EDITAR', 'reservas', $id,
                    json_encode(['antes'=>$before, 'despues'=>$after], JSON_UNESCAPED_UNICODE));
            });
        } finally {
            query($this->db, "SELECT RELEASE_LOCK('arena7_reservas')");
        }
    }

    public function cancel(int $id, array $user): void {
        transaction($this->db, function () use ($id, $user) {
            $old = query($this->db, 'SELECT estado FROM reservas WHERE id=? FOR UPDATE', [$id])->get_result()->fetch_assoc();
            if (!$old || $old['estado'] === 'Cancelado') throw new ApiError('Reserva no encontrada.', 404);
            query($this->db, "UPDATE reservas SET estado='Cancelado',hold_until=NULL WHERE id=?", [$id]);
            query($this->db, "UPDATE pagos SET estado='Cancelado' WHERE reserva_id=? AND estado='Pendiente'", [$id]);
            query($this->db, "UPDATE notificaciones SET estado='Cancelado' WHERE reserva_id=? AND estado IN ('Pendiente','Error')", [$id]);
            (new AuditService($this->db))->record($user['correo'], 'RESERVA_CANCELAR', 'reservas', $id,
                json_encode(['estado_anterior'=>$old['estado']], JSON_UNESCAPED_UNICODE));
        });
    }
}
