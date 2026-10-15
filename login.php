<?php
require_once __DIR__ . '/services/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'error'; exit;
    }
    $auth = new AuthService($db);
    $auth->login($_POST);
    $auth->privateData();
    echo 'ok';
} catch (ApiError $e) {
    http_response_code($e->status);
    echo 'error';
} catch (Throwable $e) {
    error_log($e);
    http_response_code(500);
    echo 'error';
}
