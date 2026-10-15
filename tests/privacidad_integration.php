<?php
require __DIR__.'/../services/bootstrap.php';

$owner = cfg('PRIVATE_DATA_OWNER', 'admin@arena7.com');
$row = query($db, 'SELECT id FROM usuarios WHERE correo=? AND rol=? LIMIT 1', [$owner, 'administrador'])->get_result()->fetch_assoc();
if (!$row) throw new RuntimeException('No existe la cuenta propietaria configurada.');

$_SESSION = ['user_id'=>(int)$row['id'], 'last_seen'=>time(), 'csrf'=>'test'];
$auth = new AuthService($db);
if ($auth->privateData()['id'] !== (int)$row['id']) throw new RuntimeException('El propietario no puede acceder.');

$previous = getenv('PRIVATE_DATA_OWNER');
putenv('PRIVATE_DATA_OWNER=otra-cuenta@example.invalid');
try {
    $auth->privateData();
    throw new RuntimeException('Se permitió acceso a una cuenta distinta del propietario.');
} catch (ApiError $error) {
    if ($error->status !== 403) throw $error;
} finally {
    if ($previous === false) putenv('PRIVATE_DATA_OWNER');
    else putenv('PRIVATE_DATA_OWNER='.$previous);
}
echo "Privacidad: propietario permitido y otra cuenta denegada OK.\n";
