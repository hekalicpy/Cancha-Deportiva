<?php
class ReservationService {
    public function __construct(private mysqli $db) {}
    public function list(): array {return query($this->db,"SELECT *, CASE WHEN estado='Pendiente' AND hold_until<NOW() THEN 'Vencido' ELSE estado END AS estado FROM reservas ORDER BY fecha DESC,hora")->get_result()->fetch_all(MYSQLI_ASSOC);}
    public function dni(string $dni): array {
        return (new DniService())->lookup($dni);
    }
    public function save(array $d,array $user,?int $id=null): array {
        foreach(['dni','cliente','telefono','cancha','fecha','hora','pago','monto'] as $k) if(!isset($d[$k])||trim((string)$d[$k])==='') throw new ApiError("Complete el campo $k.");
        if(!preg_match('/^\d{8}$/',$d['dni'])) throw new ApiError('DNI inválido: se requieren 8 dígitos.');
        if(!preg_match('/^[+0-9 ()-]{9,20}$/',$d['telefono'])) throw new ApiError('Teléfono inválido.');
        if(!in_array((string)$d['cancha'],['1','2','3','4'],true)||!in_array($d['hora'],['08','09','10','11','12','13','14','15','16','18','19','20','21'],true)) throw new ApiError('Cancha u horario inválido.');
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$d['fecha']);
        if(!$dt||$dt->format('Y-m-d')!==$d['fecha']||$d['fecha']<date('Y-m-d')) throw new ApiError('Seleccione una fecha válida que no sea pasada.');
        if(!in_array($d['pago'],['Yape','Plin','Efectivo'],true)||!is_numeric($d['monto'])||(float)$d['monto']<=0||(float)$d['monto']>999999.99) throw new ApiError('Método de pago o monto inválido.');
        if(!in_array($d['deporte']??'Fútbol',['Fútbol','Vóley'],true)) throw new ApiError('Deporte inválido.');
        if(!empty($d['email'])&&!filter_var($d['email'],FILTER_VALIDATE_EMAIL)) throw new ApiError('Correo de cliente inválido.');
        foreach(['cliente'=>150,'codigoPago'=>100,'detalle'=>2000,'email'=>254] as $key=>$max) if(mb_strlen($d[$key]??'')>$max) throw new ApiError("El campo $key es demasiado largo.");
        if($d['pago']!=='Efectivo'&&empty($d['codigoPago'])) throw new ApiError('Ingrese la referencia de la transferencia para su revisión.');
        // A DB advisory lock serializes all channels while the reservation and payment commit.
        if(!query($this->db,"SELECT GET_LOCK('arena7_reservas',5) locked")->get_result()->fetch_assoc()['locked']) throw new ApiError('Sistema ocupado, vuelva a intentar.',409);
        try {return transaction($this->db,function() use($d,$user,$id){
            $old=$id?query($this->db,'SELECT * FROM reservas WHERE id=? FOR UPDATE',[$id])->get_result()->fetch_assoc():null;
            if($id&&!$old) throw new ApiError('Reserva no encontrada.',404);
            if($old&&($old['estado']==='Cancelado'||($old['estado']==='Pendiente'&&strtotime($old['hold_until'])<time()))) throw new ApiError('Esta reserva ya no se puede editar.',409);
            if($old&&((float)$old['monto']!==(float)$d['monto']||$old['pago']!==$d['pago']||$old['codigo_pago']!==($d['codigoPago']??''))) throw new ApiError('El pago registrado no puede alterarse desde la edición de reserva.');
            $busy=query($this->db,"SELECT id FROM reservas WHERE cancha=? AND fecha=? AND hora=? AND id<>? AND (estado='Confirmado' OR (estado='Pendiente' AND hold_until>NOW())) LIMIT 1",[$d['cancha'],$d['fecha'],$d['hora'],$id??0])->get_result()->fetch_assoc();
            if($busy) throw new ApiError('La cancha ya está reservada en ese horario.',409);
            $identity=$_SESSION['identity']??[];
            $verified=($identity['dni']??null)===$d['dni']&&($identity['nombre']??null)===$d['cliente']&&($identity['expires']??0)>=time();
            $values=[$d['dni'],$d['cliente'],$d['telefono'],$d['cancha'],$d['fecha'],$d['hora'],$d['pago'],$d['codigoPago']??'',$d['monto'],$d['detalle']??'',$user['correo'],$d['email']??'',$d['deporte']??'Fútbol',$verified?'Consulta API':'Manual'];
            if($id){query($this->db,'UPDATE reservas SET dni=?,cliente=?,telefono=?,cancha=?,fecha=?,hora=?,pago=?,codigo_pago=?,monto=?,detalle=?,usuario=?,email=?,deporte=?,identidad=? WHERE id=?',[...$values,$id]);}
            else {query($this->db,"INSERT INTO reservas (dni,cliente,telefono,cancha,fecha,hora,pago,codigo_pago,monto,detalle,usuario,email,deporte,identidad,estado,hold_until) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pendiente',DATE_ADD(NOW(),INTERVAL 15 MINUTE))",$values);$id=$this->db->insert_id;(new PaymentService($this->db))->register($id,$d,$user);}
            (new AuditService($this->db))->record($user['correo'],$old?'RESERVA_EDITAR':'RESERVA_CREAR','reservas',$id);
            return query($this->db,'SELECT * FROM reservas WHERE id=?',[$id])->get_result()->fetch_assoc();
        });} finally {query($this->db,"SELECT RELEASE_LOCK('arena7_reservas')");}
    }
    public function cancel(int $id,array $user): array {
        return transaction($this->db,function() use($id,$user){
            $r=query($this->db,'SELECT * FROM reservas WHERE id=? FOR UPDATE',[$id])->get_result()->fetch_assoc();
            if(!$r) throw new ApiError('Reserva no encontrada.',404);
            if($r['estado']==='Cancelado') return ['ok'=>true];
            query($this->db,"UPDATE reservas SET estado='Cancelado',hold_until=NULL WHERE id=?",[$id]);
            query($this->db,"UPDATE pagos SET estado='Cancelado' WHERE reserva_id=? AND estado='Pendiente'",[$id]);
            query($this->db,"UPDATE notificaciones SET estado='Cancelado' WHERE reserva_id=? AND estado IN ('Pendiente','Error')",[$id]);
            (new AuditService($this->db))->record($user['correo'],'RESERVA_CANCELAR','reservas',$id,'Se conserva el historial de pagos; no se realiza reembolso automático.');return ['ok'=>true];
        });
    }
}
