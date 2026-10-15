<?php
// Temporary tables shadow production data for this connection.
require __DIR__.'/../services/bootstrap.php';
require __DIR__.'/../services/LegacyReservationService.php';

foreach (['reservas', 'pagos', 'auditoria', 'notificaciones'] as $table) {
    $definition = $db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
    $db->query(str_replace("CREATE TABLE `$table`", "CREATE TEMPORARY TABLE `$table`", $definition));
}

$user = ['correo'=>'prueba-pago@example.invalid', 'rol'=>'administrador'];
$id = (new LegacyReservationService($db))->create([
    'dni'=>'12345678', 'cliente'=>'Prueba Pago', 'telefono'=>'987654321',
    'cancha'=>'1', 'fecha'=>date('Y-m-d', strtotime('+2 days')), 'hora'=>'08',
    'pago'=>'Yape', 'codigoPago'=>'REFERENCIA-PRUEBA', 'monto'=>'50', 'detalle'=>''
], $user);
$reservation = query($db, 'SELECT estado,hold_until FROM reservas WHERE id=?', [$id])->get_result()->fetch_assoc();
$payment = query($db, 'SELECT id,estado FROM pagos WHERE reserva_id=?', [$id])->get_result()->fetch_assoc();
if ($reservation['estado'] !== 'Pendiente' || !$reservation['hold_until'] || $payment['estado'] !== 'Pendiente') {
    throw new RuntimeException('El código de operación confirmó un pago no verificado.');
}
(new PaymentService($db))->confirm((int)$payment['id'], $user);
$confirmed = query($db, 'SELECT estado FROM reservas WHERE id=?', [$id])->get_result()->fetch_assoc();
if ($confirmed['estado'] !== 'Confirmado') throw new RuntimeException('La confirmación manual falló.');
echo "Pagos: referencia pendiente y confirmación manual OK.\n";
