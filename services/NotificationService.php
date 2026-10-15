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
    public function list(): array {return query($this->db,'SELECT id,reserva_id,evento,email,estado,intentos,ultimo_error,creado FROM notificaciones ORDER BY id DESC LIMIT 500')->get_result()->fetch_all(MYSQLI_ASSOC);}
    public function enqueue(int $id, string $event = 'Confirmada'): void {
        if (!in_array($event, ['Recibida','Confirmada'], true)) throw new InvalidArgumentException('Tipo de notificación inválido.');
        $r=query($this->db,'SELECT * FROM reservas WHERE id=?',[$id])->get_result()->fetch_assoc();
        $expected = $event === 'Recibida' ? 'Pendiente' : 'Confirmado';
        if (!$r || $r['estado'] !== $expected) throw new ApiError('El estado de la reserva no permite esta notificación.', 409);
        $start=sprintf('%02d:00', (int)$r['hora']);
        $end=sprintf('%02d:00', (int)$r['hora'] + 1);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d', $r['fecha'])->format('d/m/Y');
        $intro=$event === 'Recibida'
            ? "Recibimos tu solicitud de reserva. El pago aún está pendiente de verificación; la cancha NO está confirmada.\nEl horario se aparta por 15 minutos desde el registro."
            : 'Tu cancha está agendada y confirmada.';
        $status=$event === 'Recibida' ? 'Pendiente de pago o verificación' : 'Confirmada';
        $text="$intro\n\nReserva Arena 7 #$id\nCliente: {$r['cliente']}\nCancha: {$r['cancha']} - {$r['deporte']}\nFecha: $date\nHorario: de $start a $end\nMonto: S/ {$r['monto']}\nEstado: $status\nUbicación: ".self::maps();
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
            $jobs=query($this->db,"SELECT n.* FROM notificaciones n JOIN reservas r ON r.id=n.reserva_id WHERE n.estado IN ('Pendiente','Error') AND n.intentos<5 AND (n.ultimo_intento IS NULL OR n.ultimo_intento<DATE_SUB(NOW(),INTERVAL 5 MINUTE)) AND ((n.evento='Recibida' AND r.estado='Pendiente' AND r.hold_until>NOW()) OR (n.evento='Confirmada' AND r.estado='Confirmado')) ORDER BY n.id LIMIT 20")->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($jobs as $job){
                $current=query($this->db,'SELECT estado,hold_until FROM reservas WHERE id=?',[$job['reserva_id']])->get_result()->fetch_assoc();
                if (!$current || ($job['evento']==='Recibida' && ($current['estado']!=='Pendiente' || strtotime($current['hold_until'])<=time())) || ($job['evento']==='Confirmada' && $current['estado']!=='Confirmado')) {
                    query($this->db,"UPDATE notificaciones SET estado='Cancelado' WHERE id=?",[$job['id']]);
                    continue;
                }
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
        $subject = ($job['evento']==='Recibida' ? 'Solicitud de reserva recibida (pago pendiente) Arena 7 #' : 'Confirmación de reserva Arena 7 #').$job['reserva_id'];
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
