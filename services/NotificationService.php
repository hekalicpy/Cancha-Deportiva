<?php
require_once __DIR__ . '/VenueLocation.php';
require_once __DIR__ . '/GmailMailer.php';
class NotificationService {
    public function __construct(private mysqli $db) {}
    public static function maps(): string {return VenueLocation::details()['mapa'];}
    public static function provider(): string {
        if (GmailMailer::configured()) return 'gmail';
        if (cfg('RESEND_API_KEY') !== '' && cfg('MAIL_FROM') !== '') return 'resend';
        return '';
    }
    public function list(?int $reservationId=null): array {
        $sql='SELECT id,reserva_id,evento,email,estado,intentos,ultimo_error,enviado_en,creado FROM notificaciones';
        if ($reservationId !== null) return query($this->db,$sql.' WHERE reserva_id=? ORDER BY id DESC',[$reservationId])->get_result()->fetch_all(MYSQLI_ASSOC);
        return query($this->db,$sql.' ORDER BY id DESC LIMIT 500')->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public function enqueue(int $id, string $event = 'Confirmada'): void {
        if (!in_array($event, ['Recibida','Confirmada'], true)) throw new InvalidArgumentException('Tipo de notificación inválido.');
        $r=query($this->db,'SELECT * FROM reservas WHERE id=?',[$id])->get_result()->fetch_assoc();
        $expected = $event === 'Recibida' ? 'Pendiente' : 'Confirmado';
        if (!$r || $r['estado'] !== $expected) throw new ApiError('El estado de la reserva no permite esta notificación.', 409);
        $start=sprintf('%02d:00', (int)$r['hora']);
        $end=sprintf('%02d:00', (int)$r['hora'] + 1);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d', $r['fecha'])->format('d/m/Y');
        $intro=$event === 'Recibida'
            ? "Recibimos tu solicitud de reserva. El pago aún está pendiente; la cancha NO está confirmada.\nEl horario se aparta por 10 minutos desde el registro."
            : 'Tu cancha está agendada y confirmada.';
        $status=$event === 'Recibida' ? 'Pendiente de pago o verificación' : 'Confirmada';
        $text="$intro\n\nReserva Arena 7 #$id\nCliente: {$r['cliente']}\nCancha: {$r['cancha']} - {$r['deporte']}\nFecha: $date\nHorario: de $start a $end\nMonto: S/ {$r['monto']}\nEstado: $status\nUbicación: ".self::maps();
        if ($event === 'Recibida' && $r['pago'] === 'Yape') {
            $text .= "\n\nPara pagar con Yape, escanea el código QR incluido en este correo e ingresa S/ {$r['monto']} en la aplicación. El QR personal no fija el importe automáticamente. La reserva se confirmará únicamente cuando el administrador verifique el abono.";
        } elseif ($event === 'Recibida' && $r['pago'] === 'Pasarela QR') {
            $text .= "\n\nEl QR de la pasarela aparece en la constancia de la reserva. La reserva se confirmará cuando el proveedor verifique el pago.";
        } elseif ($event === 'Recibida') {
            $text .= "\n\nMedio de pago: {$r['pago']}. Coordina el pago con el administrador. La reserva se confirmará únicamente después de verificarlo.";
        }
        $existing=query($this->db,'SELECT id,estado,intentos FROM notificaciones WHERE reserva_id=? AND evento=?',[$id,$event])->get_result()->fetch_assoc();
        if (!$existing) {
            query($this->db,'INSERT INTO notificaciones (reserva_id,evento,email,mensaje,estado) VALUES (?,?,?,?,?)',[$id,$event,$r['email'],$text,$r['email']?'Pendiente':'Sin correo']);
        } elseif ($existing['estado'] === 'Sin correo' || ($existing['estado'] === 'Pendiente' && (int)$existing['intentos'] === 0)) {
            query($this->db,'UPDATE notificaciones SET email=?,mensaje=?,estado=? WHERE id=?',[$r['email'],$text,$r['email']?'Pendiente':'Sin correo',$existing['id']]);
        }
    }
    public function process(): array {
        $provider = self::provider();
        if ($provider === '') return ['enviados'=>0,'estado'=>'Configure GMAIL_ADDRESS y GMAIL_APP_PASSWORD, o RESEND_API_KEY y MAIL_FROM.'];
        if(!query($this->db,"SELECT GET_LOCK('arena7_mail',0) locked")->get_result()->fetch_assoc()['locked']) return ['enviados'=>0,'estado'=>'Otro trabajador activo.'];
        $sent=0;
        try {
            query($this->db,"UPDATE notificaciones n JOIN reservas r ON r.id=n.reserva_id SET n.estado='Cancelado' WHERE n.evento='Recibida' AND n.estado IN ('Pendiente','Error') AND (r.estado<>'Pendiente' OR r.hold_until<=NOW())");
            $jobs=query($this->db,"SELECT n.*,r.pago AS metodo_pago FROM notificaciones n JOIN reservas r ON r.id=n.reserva_id WHERE n.estado IN ('Pendiente','Error') AND n.intentos<3 AND (n.ultimo_intento IS NULL OR (n.intentos=1 AND n.ultimo_intento<=DATE_SUB(NOW(),INTERVAL 1 MINUTE)) OR (n.intentos=2 AND n.ultimo_intento<=DATE_SUB(NOW(),INTERVAL 5 MINUTE))) AND ((n.evento='Recibida' AND r.estado='Pendiente' AND r.hold_until>NOW()) OR (n.evento='Confirmada' AND r.estado='Confirmado')) ORDER BY n.id LIMIT 20")->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($jobs as $job){
                if (!filter_var($job['email'], FILTER_VALIDATE_EMAIL)) {
                    query($this->db,"UPDATE notificaciones SET estado='Fallido',ultimo_error='Correo de destino inválido' WHERE id=?",[$job['id']]);
                    continue;
                }
                $current=query($this->db,'SELECT estado,hold_until FROM reservas WHERE id=?',[$job['reserva_id']])->get_result()->fetch_assoc();
                if (!$current || ($job['evento']==='Recibida' && ($current['estado']!=='Pendiente' || strtotime($current['hold_until'])<=time())) || ($job['evento']==='Confirmada' && $current['estado']!=='Confirmado')) {
                    query($this->db,"UPDATE notificaciones SET estado='Cancelado' WHERE id=?",[$job['id']]);
                    continue;
                }
                query($this->db,'UPDATE notificaciones SET intentos=intentos+1,ultimo_intento=NOW() WHERE id=?',[$job['id']]);
                try {
                    $this->send($provider, $job);
                    query($this->db,"UPDATE notificaciones SET estado='Enviado',ultimo_error=NULL,enviado_en=NOW() WHERE id=?",[$job['id']]);$sent++;
                }catch(Throwable $e){query($this->db,'UPDATE notificaciones SET estado=?,ultimo_error=? WHERE id=?',[(int)$job['intentos']+1>=3?'Fallido':'Error',$e->getMessage(),$job['id']]);}
            }
        } finally {query($this->db,"SELECT RELEASE_LOCK('arena7_mail')");}
        return ['enviados'=>$sent];
    }

    private function send(string $provider, array $job): void {
        $subject = ($job['evento']==='Recibida' ? 'Solicitud de reserva recibida (pago pendiente) Arena 7 #' : 'Confirmación de reserva Arena 7 #').$job['reserva_id'];
        $withYapeQr = $job['evento'] === 'Recibida' && $job['metodo_pago'] === 'Yape';
        $qrPath = $withYapeQr ? __DIR__.'/../assets/yape-qr-codigo.png' : null;
        if ($qrPath !== null && !is_readable($qrPath)) throw new RuntimeException('No se encuentra el QR de Yape para el correo.');
        $html = $withYapeQr ? self::paymentHtml($job['mensaje']) : null;
        if ($provider === 'resend') {
            $payload = [
                'from'=>cfg('MAIL_FROM'), 'to'=>[$job['email']], 'subject'=>$subject, 'text'=>$job['mensaje']
            ];
            if ($withYapeQr) {
                $payload['html'] = $html;
                $payload['attachments'] = [[
                    'filename'=>'yape-qr.png', 'content'=>base64_encode(file_get_contents($qrPath)),
                    'content_type'=>'image/png', 'content_id'=>'yape-qr'
                ]];
            }
            $data = externalJson('https://api.resend.com/emails', cfg('RESEND_API_KEY'), $payload, ['Idempotency-Key: arena7-confirmacion-'.$job['id']]);
            if (empty($data['id'])) throw new ApiError('El proveedor no confirmó la aceptación del mensaje.', 502);
            return;
        }

        GmailMailer::send($job['email'], $subject, $job['mensaje'], $html, $qrPath);
    }

    public static function paymentHtml(string $message): string {
        return '<!doctype html><html lang="es"><body style="font-family:Arial,sans-serif;line-height:1.5;color:#202124">'
            .nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            .'<p><img src="cid:yape-qr" alt="Código QR para pagar con Yape" width="320" style="max-width:100%;height:auto"></p>'
            .'</body></html>';
    }
}
