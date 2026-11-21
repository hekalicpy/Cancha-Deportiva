<?php
final class EsbClient {
    public static function post(string $route, array $payload): array {
        if (!in_array($route, ['identidad/dni', 'pagos/qr'], true)) {
            throw new InvalidArgumentException('Ruta ESB no permitida.');
        }
        $secret = cfg('ARENA7_ESB_SECRET');
        if ($secret === '') throw new ApiError('La capa de integración no está disponible.', 503);
        $root = rtrim((string)getenv('APPDATA'), '\\/').'/Caddy/pki/authorities/local/root.crt';
        if (!is_readable($root)) throw new ApiError('No se encuentra el certificado local de la capa de integración.', 503);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ch = curl_init('https://localhost:8444/esb.php/'.$route);
        curl_setopt_array($ch, [
            CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_CAINFO=>$root,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: application/json', 'X-Arena7-ESB: '.$secret],
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new ApiError('La capa de integración no responde por HTTPS.', 503);
        $result = json_decode($raw, true);
        if (!is_array($result)) throw new ApiError('Respuesta inválida de la capa de integración.', 502);
        if ($status < 200 || $status >= 300) {
            $message = is_string($result['error'] ?? null) ? $result['error'] : 'El proveedor no pudo completar la operación.';
            throw new ApiError($message, in_array($status, [400, 422, 502, 503], true) ? $status : 502);
        }
        return $result;
    }

    public static function healthy(): bool {
        $root = rtrim((string)getenv('APPDATA'), '\\/').'/Caddy/pki/authorities/local/root.crt';
        if (!is_readable($root)) return false;
        $ch = curl_init('https://localhost:8444/esb.php/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>1,
            CURLOPT_TIMEOUT=>2, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_CAINFO=>$root]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $raw !== false && $status === 200 && (json_decode($raw, true)['estado'] ?? '') === 'operativo';
    }
}
