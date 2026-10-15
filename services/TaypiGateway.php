<?php
final class TaypiGateway {
    public static function mode(): string {
        $mode = cfg('TAYPI_MODE', 'sandbox');
        if (!in_array($mode, ['sandbox', 'live'], true)) throw new ApiError('Modo de pasarela inválido.', 503);
        return $mode;
    }

    public static function configured(): bool {
        $mode = self::mode();
        $suffix = $mode === 'live' ? 'live' : 'test';
        return preg_match('/^taypi_pk_'.$suffix.'_[a-f0-9]{32}$/i', cfg('TAYPI_PUBLIC_KEY')) === 1
            && preg_match('/^taypi_sk_'.$suffix.'_[a-f0-9]{64}$/i', cfg('TAYPI_SECRET_KEY')) === 1
            && cfg('TAYPI_WEBHOOK_SECRET') !== '';
    }

    public static function verifyWebhook(string $body, string $signature, string $timestamp): bool {
        $secret = cfg('TAYPI_WEBHOOK_SECRET');
        if ($secret === '' || !preg_match('/^sha256=([a-f0-9]{64})$/i', $signature, $m)
            || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) return false;
        return hash_equals(hash_hmac('sha256', $body, $secret), strtolower($m[1]));
    }

    public function createPayment(string $amount, string $reference): array {
        if (!self::configured()) throw new ApiError('La pasarela todavía no está configurada.', 503);
        $path = '/api/v1/payments';
        $body = json_encode([
            'amount'=>$amount, 'currency'=>'PEN', 'reference'=>$reference,
            'description'=>'Reserva Arena 7 '.$reference,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, 'POST', $path, $body]), cfg('TAYPI_SECRET_KEY'));
        $host = self::mode() === 'live' ? 'https://app.taypi.pe' : 'https://sandbox.taypi.pe';
        $ch = curl_init($host.$path);
        $options = [
            CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json', 'Authorization: Bearer '.cfg('TAYPI_PUBLIC_KEY'),
                'Taypi-Signature: '.$signature, 'Taypi-Timestamp: '.$timestamp,
                'Idempotency-Key: '.$reference,
            ],
        ];
        if (cfg('CA_BUNDLE_PATH') !== '') {
            if (!is_readable(cfg('CA_BUNDLE_PATH'))) throw new ApiError('No se encuentra el paquete de certificados TLS del servidor.', 503);
            $options[CURLOPT_CAINFO] = cfg('CA_BUNDLE_PATH');
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            error_log('TAYPI conexión: '.$error);
            throw new ApiError('No se pudo conectar con la pasarela; vuelva a intentar.', 502);
        }
        if ($code < 200 || $code >= 300) {
            error_log('TAYPI HTTP '.$code);
            throw new ApiError('La pasarela no pudo generar el cobro; vuelva a intentar.', 502);
        }
        return self::parsePaymentResponse($raw);
    }

    public static function parsePaymentResponse(string $raw): array {
        $envelope = json_decode($raw, true);
        $result = is_array($envelope) ? ($envelope['data'] ?? null) : null;
        if (!is_array($result)) throw new ApiError('La pasarela devolvió una respuesta inválida.', 502);
        $qr = $result['qr_image'] ?? '';
        $providerId = $result['payment_id'] ?? '';
        if (!is_string($providerId) || !preg_match('/^[A-Za-z0-9-]{10,100}$/', $providerId)
            || !is_string($qr) || strlen($qr) > 200000
            || !preg_match('#^data:image/(?:svg\+xml|png);base64,[A-Za-z0-9+/]+={0,2}$#', $qr)) {
            throw new ApiError('La pasarela no devolvió un QR válido.', 502);
        }
        return ['payment_id'=>$providerId, 'qr_image'=>$qr];
    }
}
