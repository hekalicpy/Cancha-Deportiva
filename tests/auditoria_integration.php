<?php
// Uses temporary tables that shadow the live tables only for this connection.
require __DIR__.'/../services/bootstrap.php';
require __DIR__.'/../services/LegacyReservationService.php';

foreach (['reservas', 'auditoria', 'pagos', 'notificaciones'] as $table) {
    $definition = $db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
    $db->query(str_replace("CREATE TABLE `$table`", "CREATE TEMPORARY TABLE `$table`", $definition));
}

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$user = ['correo'=>'prueba-auditoria@example.invalid', 'rol'=>'administrador'];
$service = new LegacyReservationService($db);
$day = date('Y-m-d', strtotime('+2 days'));
$data = [
    'dni'=>'12345678', 'cliente'=>'Prueba Auditoría', 'telefono'=>'987654321',
    'cancha'=>'1', 'fecha'=>$day, 'hora'=>'08', 'pago'=>'Efectivo',
    'codigoPago'=>'', 'monto'=>'50', 'detalle'=>'Prueba aislada'
];

$id = $service->create($data, $user);
check($id > 0, 'No se creó la reserva.');
check((int)query($db, 'SELECT COUNT(*) n FROM auditoria WHERE accion=? AND recurso_id=?', ['RESERVA_CREAR', $id])->get_result()->fetch_assoc()['n'] === 1,
    'La creación no quedó en auditoría.');

try {
    $service->create($data, $user);
    throw new RuntimeException('Se permitió reservar dos veces el mismo horario.');
} catch (ApiError $e) {
    check($e->status === 409, 'La colisión no devolvió 409.');
}

$data['hora'] = '09';
$service->edit($id, $data, $user);
check(query($db, 'SELECT hora FROM reservas WHERE id=?', [$id])->get_result()->fetch_assoc()['hora'] === '09',
    'La edición no se guardó.');
$edit = query($db, 'SELECT detalle FROM auditoria WHERE accion=? AND recurso_id=?', ['RESERVA_EDITAR', $id])->get_result()->fetch_assoc();
check($edit && isset(json_decode($edit['detalle'], true)['antes']['hora']), 'La edición no guardó el valor anterior.');

$service->cancel($id, $user);
check(query($db, 'SELECT estado FROM reservas WHERE id=?', [$id])->get_result()->fetch_assoc()['estado'] === 'Cancelado',
    'La cancelación eliminó o no actualizó la reserva.');
$reservationEvents = query($db, "SELECT accion FROM auditoria WHERE recurso='reservas' AND recurso_id=?", [$id])->get_result()->fetch_all(MYSQLI_ASSOC);
check(count($reservationEvents) === 4 && count(array_intersect(
    array_column($reservationEvents, 'accion'),
    ['RESERVA_CREAR', 'RESERVA_CONFIRMAR', 'RESERVA_EDITAR', 'RESERVA_CANCELAR']
)) === 4, 'Falta un evento de auditoría de la reserva.');

$rows = (new AuditService($db))->list(['usuario'=>$user['correo'], 'reserva_id'=>$id, 'accion'=>'RESERVA_CANCELAR']);
check(count($rows) === 1, 'El filtro de auditoría no devolvió la cancelación.');
check((new AuditService($db))->list(['before_id'=>$rows[0]['id'], 'accion'=>'RESERVA_CANCELAR']) === [],
    'La paginación de auditoría falló.');

echo "Auditoría: crear, evitar duplicado, editar, cancelar, filtrar y paginar OK.\n";
