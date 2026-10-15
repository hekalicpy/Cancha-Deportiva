<?php
require __DIR__.'/services/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('Método no permitido.', 405);
    $body = file_get_contents('php://input');
    if (!is_string($body) || strlen($body) > 65536) throw new ApiError('Aviso demasiado grande.', 413);
    respond((new GatewayPaymentService($db))->webhook(
        $body, $_SERVER['HTTP_TAYPI_SIGNATURE'] ?? '', $_SERVER['HTTP_TAYPI_TIMESTAMP'] ?? ''
    ));
} catch (ApiError $e) {
    respond(['error'=>$e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log($e);
    respond(['error'=>'No se pudo procesar el aviso de pago.'], 500);
}
