<?php
require __DIR__.'/services/bootstrap.php';
require __DIR__.'/services/LegacyReservationService.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('Método no permitido.', 405);
    $auth = new AuthService($db);
    $user = $auth->requireUser();
    $auth->csrf();
    $id = (new LegacyReservationService($db))->create($_POST, $user);
    header('X-Reserva-Id: '.$id);
    echo 'ok';
} catch (ApiError $e) {
    http_response_code($e->status);
    echo $e->getMessage();
} catch (Throwable $e) {
    error_log($e);
    http_response_code(500);
    echo 'No se pudo guardar la reserva.';
}
