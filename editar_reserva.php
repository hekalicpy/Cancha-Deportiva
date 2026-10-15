<?php
require __DIR__.'/services/bootstrap.php';
require __DIR__.'/services/LegacyReservationService.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('Método no permitido.', 405);
    $auth = new AuthService($db);
    $user = $auth->privateData();
    $auth->csrf();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id === false || $id === null) throw new ApiError('ID de reserva inválido.');
    (new LegacyReservationService($db))->edit($id, $_POST, $user);
    echo 'ok';
} catch (ApiError $e) {
    http_response_code($e->status);
    echo $e->getMessage();
} catch (Throwable $e) {
    error_log($e);
    http_response_code(500);
    echo 'No se pudo editar la reserva.';
}
