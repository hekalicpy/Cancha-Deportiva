<?php
class AuditService {
    public function __construct(private mysqli $db) {}
    public function record(string $user,string $action,string $resource,?int $id=null,?string $detail=null): void {
        query($this->db,'INSERT INTO auditoria (usuario,accion,recurso,recurso_id,detalle) VALUES (?,?,?,?,?)',[$user,$action,$resource,$id,$detail]);
    }
    public function list(array $filters): array {
        $id=filter_var($filters['reserva_id']??null,FILTER_VALIDATE_INT);
        return query($this->db,'SELECT * FROM auditoria '.($id?'WHERE recurso="reservas" AND recurso_id=? ':'').'ORDER BY id DESC LIMIT 500',$id?[$id]:[])->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
