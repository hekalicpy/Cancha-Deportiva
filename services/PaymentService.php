<?php
class PaymentService {
    public function __construct(private mysqli $db) {}
    public function list(): array {return query($this->db,'SELECT p.*,r.cliente,r.estado AS reserva_estado FROM pagos p JOIN reservas r ON r.id=p.reserva_id ORDER BY p.id DESC LIMIT 500')->get_result()->fetch_all(MYSQLI_ASSOC);}
    private function validateCode(string $method, string $code, ?int $exclude = null): void {
        if (!in_array($method, ['Yape','Plin'], true)) return;
        if ($code === '') return;
        $duplicate = query($this->db,"SELECT id FROM pagos WHERE codigo=? AND estado<>'Cancelado' AND id<>? LIMIT 1",[$code,$exclude ?? 0])->get_result()->fetch_assoc();
        if ($duplicate) throw new ApiError('Este código de operación ya está registrado.',409);
    }
    public function register(int $reservation,array $d,array $user,bool $paid=false): void {
        // A transfer reference is evidence for review, never a bank validation.
        $code=trim((string)($d['codigoPago']??''));
        if ($paid && in_array($d['pago'],['Yape','Plin'],true) && $code==='') throw new ApiError('Indique el código de operación del pago recibido.');
        $this->validateCode($d['pago'],$code);
        if ($paid && query($this->db,"SELECT id FROM pagos WHERE reserva_id=? AND estado='Confirmado' LIMIT 1",[$reservation])->get_result()->fetch_assoc()) throw new ApiError('Esta reserva ya tiene un pago aprobado.',409);
        $status=$paid?'Confirmado':'Pendiente';
        query($this->db,'INSERT INTO pagos (reserva_id,metodo,codigo,monto,estado,usuario) VALUES (?,?,?,?,?,?)',[$reservation,$d['pago'],$code,$d['monto'],$status,$user['correo']]);
        (new AuditService($this->db))->record($user['correo'],'PAGO_REGISTRAR','pagos',$this->db->insert_id,$paid?'Pago recibido y verificado por operador':($d['pago']==='Pasarela QR'?'Cobro pendiente de la pasarela':'Pago pendiente de revisión manual'));
        if($paid) {$this->confirmReservation($reservation,$user);}
    }
    private function confirmReservation(int $id,array $user): void {
        query($this->db,"UPDATE reservas SET estado='Confirmado',hold_until=NULL WHERE id=?",[$id]);
        query($this->db,"UPDATE notificaciones SET estado='Cancelado' WHERE reserva_id=? AND evento='Recibida' AND estado IN ('Pendiente','Error')",[$id]);
        (new NotificationService($this->db))->enqueue($id);
        (new AuditService($this->db))->record($user['correo'],'RESERVA_CONFIRMAR','reservas',$id);
    }
    public function confirm(int $id,array $user,?string $submittedCode=null): array {
        if($user['rol']!=='administrador') throw new ApiError('Se requiere un administrador.',403);
        if (!query($this->db,"SELECT GET_LOCK('arena7_reservas',5) AS locked")->get_result()->fetch_assoc()['locked']) throw new ApiError('Sistema ocupado, vuelva a intentar.',409);
        try { return transaction($this->db,function() use($id,$user,$submittedCode){
            $p=query($this->db,'SELECT * FROM pagos WHERE id=?',[$id])->get_result()->fetch_assoc();if(!$p) throw new ApiError('Pago no encontrado.',404);
            if($p['metodo']==='Pasarela QR') throw new ApiError('El pago por pasarela solo se confirma con el aviso verificado del proveedor.',409);
            $r=query($this->db,'SELECT * FROM reservas WHERE id=? FOR UPDATE',[$p['reserva_id']])->get_result()->fetch_assoc();
            $p=query($this->db,'SELECT * FROM pagos WHERE id=? FOR UPDATE',[$id])->get_result()->fetch_assoc();
            if($p['estado']==='Confirmado') return ['ok'=>true,'comprobante'=>'PAGO-'.$id];
            if($r['estado']!=='Pendiente'||strtotime($r['hold_until'])<time()) throw new ApiError('La reserva está cancelada o su bloqueo venció. No se puede confirmar el pago.',409);
            $code=trim((string)($submittedCode ?? $p['codigo']));
            if (strlen($code)>100) throw new ApiError('El código de operación es demasiado largo.');
            if (in_array($p['metodo'],['Yape','Plin'],true) && $code==='') throw new ApiError('Indique el código de operación antes de confirmar.');
            $this->validateCode($p['metodo'],$code,$id);
            if (query($this->db,"SELECT id FROM pagos WHERE reserva_id=? AND estado='Confirmado' AND id<>? LIMIT 1",[$p['reserva_id'],$id])->get_result()->fetch_assoc()) throw new ApiError('Esta reserva ya tiene un pago aprobado.',409);
            query($this->db,"UPDATE pagos SET estado='Confirmado',codigo=?,usuario=? WHERE id=?",[$code,$user['correo'],$id]);$this->confirmReservation((int)$p['reserva_id'],$user);
            (new AuditService($this->db))->record($user['correo'],'PAGO_CONFIRMAR_MANUAL','pagos',$id,'El administrador confirmó la recepción del dinero.');return ['ok'=>true,'comprobante'=>'PAGO-'.$id];
        }); } finally { query($this->db,"SELECT RELEASE_LOCK('arena7_reservas')"); }
    }
}
