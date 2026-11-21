<?php
// Proceso FastCGI independiente: media proveedores sin abrir la base de datos de Arena 7.
class ApiError extends RuntimeException {
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}
function cfg(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}
function esbRespond(array $value, int $status = 200): never {
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $route = trim(substr($path, strpos($path, 'esb.php') + 7), '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($route === 'health' && $method === 'GET') {
        esbRespond(['estado'=>'operativo', 'modelo'=>'ESB ligero', 'transporte'=>'HTTPS',
            'rutas'=>['identidad/dni', 'pagos/qr']]);
    }
    $secret = cfg('ARENA7_ESB_SECRET');
    if ($secret === '' || !hash_equals($secret, $_SERVER['HTTP_X_ARENA7_ESB'] ?? '')) {
        throw new ApiError('Acceso no autorizado a la capa de integración.', 401);
    }
    if ($method !== 'POST') throw new ApiError('Método no permitido.', 405);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) throw new ApiError('Se requiere un cuerpo JSON válido.', 400);
    if ($route === 'identidad/dni') {
        $dni = $input['dni'] ?? '';
        if (!is_string($dni) || !preg_match('/^\d{8}$/', $dni)) throw new ApiError('El DNI debe tener 8 dígitos.');
        $token = cfg('RENIEC_TOKEN');
        if ($token === '') throw new ApiError('Consulta DNI no configurada. Ingrese el nombre manualmente o configure el token de Decolecta.', 503);
        $url = 'https://api.decolecta.com/v1/reniec/dni?numero='.rawurlencode($dni);
        $ch = curl_init($url);
        $options = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token, 'Accept: application/json']];
        if (cfg('CA_BUNDLE_PATH') !== '') $options[CURLOPT_CAINFO] = cfg('CA_BUNDLE_PATH');
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new ApiError('No se pudo conectar de forma segura con el proveedor de DNI.', 502);
        if ($status === 429) throw new ApiError('Se alcanzó el límite de consultas del proveedor.', 503);
        if ($status < 200 || $status >= 300) throw new ApiError('El proveedor de DNI no pudo completar la consulta.', 502);
        $data = json_decode($raw, true);
        if (!is_array($data) || (string)($data['document_number'] ?? '') !== $dni
            || !is_string($data['full_name'] ?? null) || trim($data['full_name']) === '') {
            throw new ApiError('El proveedor no devolvió datos coincidentes con el DNI solicitado.', 502);
        }
        esbRespond(['document_number'=>$dni, 'full_name'=>trim($data['full_name'])]);
    }
    if ($route === 'pagos/qr') {
        $amount = $input['amount'] ?? '';
        $reference = $input['reference'] ?? '';
        if (!is_string($amount) || !preg_match('/^[0-9]+\.[0-9]{2}$/', $amount)
            || !is_string($reference) || !preg_match('/^ARENA7-[0-9]+$/', $reference)) {
            throw new ApiError('Datos de cobro inválidos.');
        }
        require_once __DIR__.'/services/TaypiGateway.php';
        esbRespond((new TaypiGateway())->createPaymentDirect($amount, $reference));
    }
    throw new ApiError('Ruta de integración no encontrada.', 404);
} catch (ApiError $e) {
    esbRespond(['error'=>$e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('ESB: '.$e->getMessage());
    esbRespond(['error'=>'No se pudo completar la integración.'], 500);
}
