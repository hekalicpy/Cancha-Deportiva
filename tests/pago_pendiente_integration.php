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
    'pago'=>'Yape', 'codigoPago'=>'REFERENCIA-PRUEBA', 'monto'=>'40', 'detalle'=>''
], $user);
$reservation = query($db, 'SELECT estado,hold_until FROM reservas WHERE id=?', [$id])->get_result()->fetch_assoc();
$payment = query($db, 'SELECT id,estado FROM pagos WHERE reserva_id=?', [$id])->get_result()->fetch_assoc();
if ($reservation['estado'] !== 'Pendiente' || !$reservation['hold_until'] || $payment['estado'] !== 'Pendiente') {
    throw new RuntimeException('El código de operación confirmó un pago no verificado.');
}
if (strtotime($reservation['hold_until'])-time()<590 || strtotime($reservation['hold_until'])-time()>610) throw new RuntimeException('El bloqueo de pago no dura 10 minutos.');
(new PaymentService($db))->confirm((int)$payment['id'], $user);
$confirmed = query($db, 'SELECT estado FROM reservas WHERE id=?', [$id])->get_result()->fetch_assoc();
if ($confirmed['estado'] !== 'Confirmado') throw new RuntimeException('La confirmación manual falló.');
try {
    (new LegacyReservationService($db))->create([
        'dni'=>'12345678','cliente'=>'Tarifa errónea','telefono'=>'987654321',
        'cancha'=>'2','fecha'=>date('Y-m-d', strtotime('+2 days')),'hora'=>'08',
        'pago'=>'Efectivo','codigoPago'=>'','monto'=>'60','detalle'=>''
    ],$user);
    throw new RuntimeException('Se aceptó la tarifa nocturna para un horario diurno.');
} catch (ApiError $e) {
    if ($e->status !== 422) throw $e;
}
try {
    (new LegacyReservationService($db))->create([
        'dni'=>'12345678','cliente'=>'Código duplicado','telefono'=>'987654321',
        'cancha'=>'2','fecha'=>date('Y-m-d', strtotime('+2 days')),'hora'=>'08',
        'pago'=>'Yape','codigoPago'=>'REFERENCIA-PRUEBA','monto'=>'40','detalle'=>''
    ],$user);
    throw new RuntimeException('Se aceptó un código de operación duplicado.');
} catch (ApiError $e) {
    if ($e->status !== 409) throw $e;
}
$nightId=(new LegacyReservationService($db))->create([
    'dni'=>'12345678','cliente'=>'Tarifa noche','telefono'=>'987654321',
    'cancha'=>'2','fecha'=>date('Y-m-d', strtotime('+2 days')),'hora'=>'18',
    'pago'=>'Efectivo','codigoPago'=>'','monto'=>'60','detalle'=>''
],$user);
if (query($db,'SELECT monto FROM pagos WHERE reserva_id=?',[$nightId])->get_result()->fetch_assoc()['monto'] !== '60.00') throw new RuntimeException('No se aplicó la tarifa nocturna.');
echo "Pagos: tarifas diurna/nocturna, código único y confirmación manual OK.\n";
