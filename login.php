<?php
require_once __DIR__ . '/services/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'error'; exit;
    }
    $correo = strtolower(trim($_POST['correo'] ?? ''));
    $clave = $_POST['clave'] ?? '';
    $u = query($db, 'SELECT id, correo, password, rol FROM usuarios WHERE correo=? LIMIT 1', [$correo])->get_result()->fetch_assoc();
    if (!$u || !password_verify($clave, $u['password'])) {
        (new AuditService($db))->record($correo, 'LOGIN_FAIL', 'usuarios', $u['id'] ?? null);
        echo 'error'; exit;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    (new AuditService($db))->record($correo, 'LOGIN_OK', 'usuarios', (int)$u['id']);
    echo 'ok';
} catch (Throwable $e) {
    error_log($e);
    http_response_code(500);
    echo 'error';
}
