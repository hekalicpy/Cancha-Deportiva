<?php
require __DIR__.'/services/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $requestPath=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH) ?: '';
    $route=trim($_SERVER['PATH_INFO']??'','/');
    if($route==='') {
        $marker=strpos($requestPath,'api.php');
        $route=$marker===false?'':trim(substr($requestPath,$marker+7),'/');
    }
    $method=$_SERVER['REQUEST_METHOD'];
    $input=json_decode(file_get_contents('php://input'),true)?:$_POST;
    $auth=new AuthService($db);
    if($route==='auth/captcha'&&$method==='GET') respond($auth->captcha());
    if($route==='auth/login'&&$method==='POST') respond($auth->login($input));
    $user=$auth->privateData();if($method!=='GET') $auth->csrf();
    if($route==='auth/me'&&$method==='GET') respond(['user'=>$user,'csrf'=>$_SESSION['csrf']]);
    if($route==='auth/logout'&&$method==='POST') {session_destroy();respond(['ok'=>true]);}
    if($route==='servicios'&&$method==='GET') respond(['dni'=>cfg('RENIEC_TOKEN')!=='','correo'=>NotificationService::provider()!=='','pagos'=>'Yape personal manual; pasarela QR independiente','yape_numero'=>cfg('YAPE_PHONE','989518266'),'pasarela_qr'=>TaypiGateway::configured(),'pasarela_modo'=>TaypiGateway::mode(),'pasarela_monto'=>TaypiGateway::amount(),'maps'=>NotificationService::maps()]);
    $reservations=new ReservationService($db);
    if($route==='reservas'&&$method==='GET') respond($reservations->list());
    if($route==='reservas'&&$method==='POST') respond($reservations->save($input,$user),201);
    if(preg_match('#^reservas/(\d+)$#',$route,$m)) {
        if($method==='PUT') respond($reservations->save($input,$user,(int)$m[1]));
        if($method==='DELETE') respond($reservations->cancel((int)$m[1],$user));
    }
    if($route==='reservas/dni'&&$method==='POST') respond($reservations->dni((string)($input['dni']??'')));
    if(preg_match('#^reservas/(\d+)/qr$#',$route,$m)&&$method==='POST') respond((new GatewayPaymentService($db))->qr((int)$m[1]));
    if($route==='pagos'&&$method==='GET') respond((new PaymentService($db))->list());
    if(preg_match('#^pagos/(\d+)/confirmar$#',$route,$m)&&$method==='POST') {$auth->admin();respond((new PaymentService($db))->confirm((int)$m[1],$user));}
    if($route==='notificaciones'&&$method==='GET') respond((new NotificationService($db))->list());
    if($route==='auditoria'&&$method==='GET') {$auth->admin();respond((new AuditService($db))->list($_GET));}
    throw new ApiError('Ruta no encontrada.',404);
} catch(ApiError $e) {respond(['error'=>$e->getMessage()],$e->status);}
catch(Throwable $e) {error_log($e);respond(['error'=>'No se pudo completar la operación. Revise el registro del servidor.'],500);}
