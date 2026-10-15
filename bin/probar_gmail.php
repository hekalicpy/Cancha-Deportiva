<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../services/GmailMailer.php';

$recipient = $argv[1] ?? '';
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/probar_gmail.php destinatario@ejemplo.com\n");
    exit(1);
}

try {
    GmailMailer::send(
        $recipient,
        'Prueba de notificaciones Arena 7',
        "Este es un correo de prueba del servicio de notificaciones de Arena 7. No corresponde a una reserva ni a un cobro.\n"
    );
    echo "Gmail aceptó el mensaje de prueba. Verifique la bandeja del destinatario.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "No se pudo enviar el mensaje de prueba: ".$error->getMessage()."\n");
    exit(1);
}
