<?php
require __DIR__.'/services/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new ApiError('Método no permitido.', 405);
    (new AuthService($db))->requireUser();
    $rows = query($db, "SELECT * FROM reservas WHERE estado<>'Cancelado' ORDER BY fecha DESC,hora ASC")
        ->get_result()->fetch_all(MYSQLI_ASSOC);
    respond($rows);
} catch (ApiError $e) {
    respond(['error'=>$e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log($e);
    respond(['error'=>'No se pudieron consultar las reservas.'], 500);
}
