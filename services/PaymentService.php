<?php
class PaymentService {
    public function __construct(private mysqli $db) {}
    public function list(): array {return query($this->db,'SELECT p.*,r.cliente,r.estado AS reserva_estado FROM pagos p JOIN reservas r ON r.id=p.reserva_id ORDER BY p.id DESC LIMIT 500')->get_result()->fetch_all(MYSQLI_ASSOC);}
    public function register(int $reservation,array $d,array $user): void {
        // A transfer reference is evidence for review, never a bank validation.
        $paid=$d['pago']==='Efectivo';$status=$paid?'Confirmado':'Pendiente';
        query($this->db,'INSERT INTO pagos (reserva_id,metodo,codigo,monto,estado,usuario) VALUES (?,?,?,?,?,?)',[$reservation,$d['pago'],$d['codigoPago']??'',$d['monto'],$status,$user['correo']]);
        (new AuditService($this->db))->record($user['correo'],'PAGO_REGISTRAR','pagos',$this->db->insert_id,$paid?'Efectivo registrado por operador':($d['pago']==='Pasarela QR'?'Cobro pendiente de la pasarela':'Transferencia pendiente de revisión manual'));
        if($paid) {$this->confirmReservation($reservation,$user);}
    }
    private function confirmReservation(int $id,array $user): void {
        query($this->db,"UPDATE reservas SET estado='Confirmado',hold_until=NULL WHERE id=?",[$id]);
        (new NotificationService($this->db))->enqueue($id);
        (new AuditService($this->db))->record($user['correo'],'RESERVA_CONFIRMAR','reservas',$id);
    }
    public function confirm(int $id,array $user): array {
        if($user['rol']!=='administrador') throw new ApiError('Se requiere un administrador.',403);
        return transaction($this->db,function() use($id,$user){
            $p=query($this->db,'SELECT * FROM pagos WHERE id=?',[$id])->get_result()->fetch_assoc();if(!$p) throw new ApiError('Pago no encontrado.',404);
            if($p['metodo']==='Pasarela QR') throw new ApiError('El pago por pasarela solo se confirma con el aviso verificado del proveedor.',409);
            $r=query($this->db,'SELECT * FROM reservas WHERE id=? FOR UPDATE',[$p['reserva_id']])->get_result()->fetch_assoc();
            $p=query($this->db,'SELECT * FROM pagos WHERE id=? FOR UPDATE',[$id])->get_result()->fetch_assoc();
            if($p['estado']==='Confirmado') return ['ok'=>true,'comprobante'=>'PAGO-'.$id];
            if($r['estado']!=='Pendiente'||strtotime($r['hold_until'])<time()) throw new ApiError('La reserva está cancelada o su bloqueo venció. No se puede confirmar el pago.',409);
            query($this->db,"UPDATE pagos SET estado='Confirmado',usuario=? WHERE id=?",[$user['correo'],$id]);$this->confirmReservation((int)$p['reserva_id'],$user);
            (new AuditService($this->db))->record($user['correo'],'PAGO_CONFIRMAR_MANUAL','pagos',$id,'El administrador confirmó la recepción del dinero.');return ['ok'=>true,'comprobante'=>'PAGO-'.$id];
        });
    }
}
