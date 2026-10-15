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
    public function list(): array {return query($this->db,'SELECT id,reserva_id,email,estado,intentos,ultimo_error,creado FROM notificaciones ORDER BY id DESC LIMIT 500')->get_result()->fetch_all(MYSQLI_ASSOC);}
    public function enqueue(int $id): void {
        $r=query($this->db,'SELECT * FROM reservas WHERE id=?',[$id])->get_result()->fetch_assoc();
        if (!$r || $r['estado'] !== 'Confirmado') throw new ApiError('Solo se notifica una reserva confirmada.', 409);
        $text="Reserva Arena 7 #$id\nCliente: {$r['cliente']}\nCancha: {$r['cancha']} - {$r['deporte']}\nFecha: {$r['fecha']}\nHora: {$r['hora']}:00 (1 hora)\nMonto: S/ {$r['monto']}\nUbicación: ".self::maps();
        $existing=query($this->db,'SELECT id,estado,intentos FROM notificaciones WHERE reserva_id=?',[$id])->get_result()->fetch_assoc();
        if (!$existing) {
            query($this->db,'INSERT INTO notificaciones (reserva_id,email,mensaje,estado) VALUES (?,?,?,?)',[$id,$r['email'],$text,$r['email']?'Pendiente':'Sin correo']);
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
            $jobs=query($this->db,"SELECT * FROM notificaciones WHERE estado IN ('Pendiente','Error') AND intentos<5 AND (ultimo_intento IS NULL OR ultimo_intento<DATE_SUB(NOW(),INTERVAL 5 MINUTE)) ORDER BY id LIMIT 20")->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($jobs as $job){
                query($this->db,'UPDATE notificaciones SET intentos=intentos+1,ultimo_intento=NOW() WHERE id=?',[$job['id']]);
                try {
                    $this->send($provider, $job);
                    query($this->db,"UPDATE notificaciones SET estado='Enviado',ultimo_error=NULL WHERE id=?",[$job['id']]);$sent++;
                }catch(Throwable $e){query($this->db,"UPDATE notificaciones SET estado='Error',ultimo_error=? WHERE id=?",[$e->getMessage(),$job['id']]);}
            }
        } finally {query($this->db,"SELECT RELEASE_LOCK('arena7_mail')");}
        return ['enviados'=>$sent];
    }

    private function send(string $provider, array $job): void {
        $subject = 'Confirmación de reserva Arena 7 #'.$job['reserva_id'];
        if ($provider === 'resend') {
            $data = externalJson('https://api.resend.com/emails', cfg('RESEND_API_KEY'), [
                'from'=>cfg('MAIL_FROM'), 'to'=>[$job['email']], 'subject'=>$subject, 'text'=>$job['mensaje']
            ], ['Idempotency-Key: arena7-confirmacion-'.$job['id']]);
            if (empty($data['id'])) throw new ApiError('El proveedor no confirmó la aceptación del mensaje.', 502);
            return;
        }

        GmailMailer::send($job['email'], $subject, $job['mensaje']);
    }
}
