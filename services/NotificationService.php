<?php
require_once __DIR__ . '/VenueLocation.php';
class NotificationService {
    public function __construct(private mysqli $db) {}
    public static function maps(): string {return VenueLocation::details()['mapa'];}
    public function list(): array {return query($this->db,'SELECT id,reserva_id,email,estado,intentos,ultimo_error,creado FROM notificaciones ORDER BY id DESC LIMIT 500')->get_result()->fetch_all(MYSQLI_ASSOC);}
    public function enqueue(int $id): void {
        $r=query($this->db,'SELECT * FROM reservas WHERE id=?',[$id])->get_result()->fetch_assoc();
        $text="Reserva Arena 7 #$id\nCliente: {$r['cliente']}\nCancha: {$r['cancha']} - {$r['deporte']}\nFecha: {$r['fecha']}\nHora: {$r['hora']}:00 (1 hora)\nMonto: S/ {$r['monto']}\nUbicación: ".self::maps();
        query($this->db,'INSERT IGNORE INTO notificaciones (reserva_id,email,mensaje,estado) VALUES (?,?,?,?)',[$id,$r['email'],$text,$r['email']?'Pendiente':'Sin correo']);
    }
    public function process(): array {
        if(!cfg('RESEND_API_KEY')||!cfg('MAIL_FROM')) return ['enviados'=>0,'estado'=>'Falta configurar RESEND_API_KEY y MAIL_FROM.'];
        if(!query($this->db,"SELECT GET_LOCK('arena7_mail',0) locked")->get_result()->fetch_assoc()['locked']) return ['enviados'=>0,'estado'=>'Otro trabajador activo.'];
        $sent=0;
        try {
            $jobs=query($this->db,"SELECT * FROM notificaciones WHERE estado IN ('Pendiente','Error') AND intentos<5 AND (ultimo_intento IS NULL OR ultimo_intento<DATE_SUB(NOW(),INTERVAL 5 MINUTE)) ORDER BY id LIMIT 20")->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($jobs as $job){
                query($this->db,'UPDATE notificaciones SET intentos=intentos+1,ultimo_intento=NOW() WHERE id=?',[$job['id']]);
                try {
                    $data=externalJson('https://api.resend.com/emails',cfg('RESEND_API_KEY'),['from'=>cfg('MAIL_FROM'),'to'=>[$job['email']],'subject'=>'Confirmación de reserva Arena 7 #'.$job['reserva_id'],'text'=>$job['mensaje']],['Idempotency-Key: arena7-confirmacion-'.$job['id']]);
                    if(empty($data['id'])) throw new ApiError('El proveedor no confirmó la aceptación del mensaje.',502);
                    query($this->db,"UPDATE notificaciones SET estado='Enviado',ultimo_error=NULL WHERE id=?",[$job['id']]);$sent++;
                }catch(Throwable $e){query($this->db,"UPDATE notificaciones SET estado='Error',ultimo_error=? WHERE id=?",[$e->getMessage(),$job['id']]);}
            }
        } finally {query($this->db,"SELECT RELEASE_LOCK('arena7_mail')");}
        return ['enviados'=>$sent];
    }
}
