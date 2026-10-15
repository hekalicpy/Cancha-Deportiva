<?php
// Temporary tables shadow production data for this connection; no external email is sent.
require __DIR__.'/../services/bootstrap.php';
require __DIR__.'/../services/LegacyReservationService.php';

foreach (['reservas', 'pagos', 'auditoria', 'notificaciones'] as $table) {
    $definition = $db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
    $db->query(str_replace("CREATE TABLE `$table`", "CREATE TEMPORARY TABLE `$table`", $definition));
}

function checkNotification(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$user = ['correo'=>'operador@example.invalid', 'rol'=>'administrador'];
$service = new LegacyReservationService($db);
$data = [
    'dni'=>'12345678', 'cliente'=>'Cliente de prueba', 'telefono'=>'987654321',
    'email'=>'cliente@example.invalid', 'cancha'=>'1',
    'fecha'=>date('Y-m-d', strtotime('+2 days')), 'hora'=>'08',
    'pago'=>'Efectivo', 'codigoPago'=>'', 'monto'=>'60', 'detalle'=>''
];

$cashId = $service->create($data, $user);
$cash = query($db, 'SELECT r.email,r.estado,n.email AS destino,n.estado AS notificacion,n.mensaje FROM reservas r JOIN notificaciones n ON n.reserva_id=r.id WHERE r.id=?', [$cashId])->get_result()->fetch_assoc();
checkNotification($cash['email'] === $data['email'] && $cash['destino'] === $data['email'], 'No se guardó el destinatario.');
checkNotification($cash['estado'] === 'Confirmado' && $cash['notificacion'] === 'Pendiente', 'Efectivo no generó la confirmación.');
checkNotification(str_contains($cash['mensaje'], 'S/ 60') && !str_contains($cash['mensaje'], $data['dni']), 'El mensaje no tiene el monto o expone el DNI.');

$data['cancha'] = '2';
$data['pago'] = 'Yape';
$data['codigoPago'] = 'REFERENCIA-PRUEBA';
$pendingId = $service->create($data, $user);
checkNotification((int)query($db, 'SELECT COUNT(*) n FROM notificaciones WHERE reserva_id=?', [$pendingId])->get_result()->fetch_assoc()['n'] === 0, 'Se notificó un pago aún pendiente.');
$payment = query($db, 'SELECT id FROM pagos WHERE reserva_id=?', [$pendingId])->get_result()->fetch_assoc();
(new PaymentService($db))->confirm((int)$payment['id'], $user);
checkNotification(query($db, 'SELECT estado FROM notificaciones WHERE reserva_id=?', [$pendingId])->get_result()->fetch_assoc()['estado'] === 'Pendiente', 'La confirmación manual no generó el correo.');

$data['cancha'] = '3';
$data['hora'] = '09';
$data['pago'] = 'Efectivo';
$data['codigoPago'] = '';
$data['email'] = '';
$withoutEmailId = $service->create($data, $user);
checkNotification(query($db, 'SELECT estado FROM notificaciones WHERE reserva_id=?', [$withoutEmailId])->get_result()->fetch_assoc()['estado'] === 'Sin correo', 'Falta el estado Sin correo.');
$data['email'] = 'nuevo@example.invalid';
$service->edit($withoutEmailId, $data, $user);
$updated = query($db, 'SELECT estado,email FROM notificaciones WHERE reserva_id=?', [$withoutEmailId])->get_result()->fetch_assoc();
checkNotification($updated['estado'] === 'Pendiente' && $updated['email'] === $data['email'], 'Editar el correo no activó la notificación.');

$data['cancha'] = '4';
$data['email'] = 'correo-invalido';
try {
    $service->create($data, $user);
    throw new RuntimeException('Se aceptó un correo inválido.');
} catch (ApiError $e) {
    checkNotification($e->status === 422, 'El correo inválido no devolvió error de validación.');
}

echo "Notificaciones: destinatario, efectivo, pago pendiente, confirmación, edición y validación OK.\n";
