<?php
require __DIR__.'/services/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new ApiError('Método no permitido.', 405);
    (new AuthService($db))->privateData();
    $rows = query($db, "SELECT r.*,p.id AS pago_id FROM reservas r LEFT JOIN pagos p ON p.reserva_id=r.id WHERE r.estado='Confirmado' OR (r.estado='Pendiente' AND r.hold_until>NOW()) ORDER BY r.fecha DESC,r.hora ASC")
        ->get_result()->fetch_all(MYSQLI_ASSOC);
    respond($rows);
} catch (ApiError $e) {
    respond(['error'=>$e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log($e);
    respond(['error'=>'No se pudieron consultar las reservas.'], 500);
}
