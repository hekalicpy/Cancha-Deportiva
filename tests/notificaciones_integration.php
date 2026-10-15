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

$oldGmailAddress = getenv('GMAIL_ADDRESS');
$oldGmailPassword = getenv('GMAIL_APP_PASSWORD');
putenv('GMAIL_ADDRESS=operador@example.invalid');
putenv('GMAIL_APP_PASSWORD='.str_repeat('x', 16));
checkNotification(NotificationService::provider() === 'gmail', 'No se activó el transporte SMTP de Gmail.');
require_once __DIR__.'/../vendor/autoload.php';
checkNotification(class_exists(\PHPMailer\PHPMailer\PHPMailer::class), 'Falta PHPMailer.');
$oldGmailAddress === false ? putenv('GMAIL_ADDRESS') : putenv('GMAIL_ADDRESS='.$oldGmailAddress);
$oldGmailPassword === false ? putenv('GMAIL_APP_PASSWORD') : putenv('GMAIL_APP_PASSWORD='.$oldGmailPassword);

$user = ['correo'=>'operador@example.invalid', 'rol'=>'administrador'];
$service = new LegacyReservationService($db);
$data = [
    'dni'=>'12345678', 'cliente'=>'Cliente de prueba', 'telefono'=>'987654321',
    'email'=>cfg('TEST_NOTIFICATION_EMAIL', 'cliente@example.invalid'), 'cancha'=>'1',
    'fecha'=>date('Y-m-d', strtotime('+2 days')), 'hora'=>'08',
    'pago'=>'Efectivo', 'codigoPago'=>'', 'monto'=>'60', 'detalle'=>''
];

$cashId = $service->create($data, $user);
$cash = query($db, 'SELECT r.email,r.estado,n.evento,n.email AS destino,n.estado AS notificacion,n.mensaje FROM reservas r JOIN notificaciones n ON n.reserva_id=r.id WHERE r.id=?', [$cashId])->get_result()->fetch_assoc();
checkNotification($cash['email'] === $data['email'] && $cash['destino'] === $data['email'], 'No se guardó el destinatario.');
checkNotification($cash['estado'] === 'Confirmado' && $cash['evento'] === 'Confirmada' && $cash['notificacion'] === 'Pendiente', 'Efectivo no generó la confirmación.');
checkNotification(str_contains($cash['mensaje'], 'S/ 60') && str_contains($cash['mensaje'], 'de 08:00 a 09:00') && !str_contains($cash['mensaje'], $data['dni']), 'La confirmación no tiene horario y monto correctos, o expone el DNI.');

$data['cancha'] = '2';
$data['pago'] = 'Yape';
$data['codigoPago'] = 'REFERENCIA-PRUEBA';
$pendingId = $service->create($data, $user);
$received = query($db, 'SELECT evento,estado,mensaje FROM notificaciones WHERE reserva_id=?', [$pendingId])->get_result()->fetch_assoc();
checkNotification($received['evento'] === 'Recibida' && $received['estado'] === 'Pendiente', 'La solicitud pendiente no generó el aviso inicial.');
checkNotification(str_contains($received['mensaje'], 'de 08:00 a 09:00') && str_contains($received['mensaje'], 'NO está confirmada') && !str_contains($received['mensaje'], $data['dni']), 'El aviso inicial no distingue el pago pendiente o expone el DNI.');
$payment = query($db, 'SELECT id FROM pagos WHERE reserva_id=?', [$pendingId])->get_result()->fetch_assoc();
(new PaymentService($db))->confirm((int)$payment['id'], $user);
$events = query($db, 'SELECT evento,estado,mensaje FROM notificaciones WHERE reserva_id=? ORDER BY id', [$pendingId])->get_result()->fetch_all(MYSQLI_ASSOC);
checkNotification(count($events) === 2 && $events[0]['evento'] === 'Recibida' && $events[0]['estado'] === 'Cancelado', 'El aviso inicial sin enviar debe cancelarse al confirmar el pago.');
checkNotification($events[1]['evento'] === 'Confirmada' && $events[1]['estado'] === 'Pendiente' && str_contains($events[1]['mensaje'], 'de 08:00 a 09:00'), 'La confirmación manual no generó el correo con el horario.');

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

echo "Notificaciones: destinatario, horario, solicitud pendiente, confirmación, edición y validación OK.\n";
