<?php
// The temporary tables shadow customer data for this connection; no provider request is made.
putenv('TAYPI_MODE=sandbox');
putenv('TAYPI_PUBLIC_KEY=taypi_pk_test_'.str_repeat('a',32));
putenv('TAYPI_SECRET_KEY=taypi_sk_test_'.str_repeat('b',64));
putenv('TAYPI_WEBHOOK_SECRET=local-webhook-test-secret');
putenv('QR_AMOUNT_PEN=60.00');
require __DIR__.'/../services/bootstrap.php';
require __DIR__.'/../services/LegacyReservationService.php';
$parsed=TaypiGateway::parsePaymentResponse(json_encode(['data'=>[
    'payment_id'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'qr_image'=>'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'),
]],JSON_THROW_ON_ERROR));
if ($parsed['payment_id']!=='aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee') throw new RuntimeException('La respuesta de la API no se procesó.');

function checkGateway(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function signedWebhook(GatewayPaymentService $service, array $event): array {
    $body=json_encode($event,JSON_THROW_ON_ERROR);
    return $service->webhook($body,'sha256='.hash_hmac('sha256',$body,cfg('TAYPI_WEBHOOK_SECRET')),(string)time());
}
foreach (['reservas','pagos','pasarela_pagos','auditoria','notificaciones'] as $table) {
    $definition=$db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
    $db->query(str_replace("CREATE TABLE `$table`","CREATE TEMPORARY TABLE `$table`",$definition));
}
$user=['correo'=>'prueba-pasarela@example.invalid','rol'=>'administrador'];
$input=[
    'dni'=>'12345678','cliente'=>'Prueba Pasarela','telefono'=>'987654321','cancha'=>'1',
    'fecha'=>date('Y-m-d',strtotime('+2 days')),'hora'=>'08','pago'=>'Pasarela QR',
    'codigoPago'=>'','monto'=>'999.00','detalle'=>'',
];
$reservationId=(new LegacyReservationService($db))->create($input,$user);
$p=query($db,'SELECT * FROM pagos WHERE reserva_id=?',[$reservationId])->get_result()->fetch_assoc();
checkGateway($p['estado']==='Pendiente' && $p['monto']==='60.00','El importe no quedó fijado en el servidor.');
$reference='ARENA7-'.$p['id'];
query($db,"INSERT INTO pasarela_pagos (pago_id,referencia,estado) VALUES (?,?,'Pendiente')",[$p['id'],$reference]);
$service=new GatewayPaymentService($db);
$event=['event'=>'payment.completed','payment_id'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'amount'=>'60.00','currency'=>'PEN','status'=>'completed','reference'=>$reference];
try {
    $service->webhook(json_encode($event),'sha256='.str_repeat('0',64),(string)time());
    throw new RuntimeException('Se aceptó un webhook sin firma válida.');
} catch (ApiError $e) { checkGateway($e->status===401,'Respuesta errónea ante firma inválida.'); }
try {
    signedWebhook($service,[...$event,'amount'=>'1.00']);
    throw new RuntimeException('Se aceptó un importe diferente.');
} catch (ApiError $e) { checkGateway($e->status===409,'Respuesta errónea ante monto incorrecto.'); }
try {
    (new PaymentService($db))->confirm((int)$p['id'],$user);
    throw new RuntimeException('Se permitió confirmar manualmente un pago de pasarela.');
} catch (ApiError $e) { checkGateway($e->status===409,'Respuesta errónea ante confirmación manual.'); }
signedWebhook($service,$event);
signedWebhook($service,$event);
$states=query($db,'SELECT r.estado AS reserva,p.estado AS pago,g.estado AS pasarela FROM reservas r JOIN pagos p ON p.reserva_id=r.id JOIN pasarela_pagos g ON g.pago_id=p.id WHERE r.id=?',[$reservationId])->get_result()->fetch_assoc();
checkGateway($states['reserva']==='Confirmado' && $states['pago']==='Confirmado' && $states['pasarela']==='Confirmado','El pago firmado no confirmó la reserva.');
checkGateway((int)query($db,"SELECT COUNT(*) n FROM auditoria WHERE accion='PAGO_CONFIRMAR_PASARELA' AND recurso_id=?",[$p['id']])->get_result()->fetch_assoc()['n']===1,'El webhook repetido duplicó la confirmación.');

$input['hora']='09';
$lateId=(new LegacyReservationService($db))->create($input,$user);
$latePayment=query($db,'SELECT id FROM pagos WHERE reserva_id=?',[$lateId])->get_result()->fetch_assoc();
$lateReference='ARENA7-'.$latePayment['id'];
query($db,"INSERT INTO pasarela_pagos (pago_id,referencia,estado) VALUES (?,?,'Pendiente')",[$latePayment['id'],$lateReference]);
query($db,'UPDATE reservas SET hold_until=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?',[$lateId]);
signedWebhook($service,[...$event,'reference'=>$lateReference,'payment_id'=>'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee']);
$late=query($db,'SELECT r.estado AS reserva,g.estado AS pasarela FROM reservas r JOIN pagos p ON p.reserva_id=r.id JOIN pasarela_pagos g ON g.pago_id=p.id WHERE r.id=?',[$lateId])->get_result()->fetch_assoc();
checkGateway($late['reserva']==='Pendiente' && $late['pasarela']==='Revisar','Un cobro tardío confirmó una reserva vencida.');
echo "Pasarela: monto fijo, firma, idempotencia y cobro tardío OK.\n";
