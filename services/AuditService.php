<?php
class AuditService {
    public function __construct(private mysqli $db) {}

    public function record(string $user, string $action, string $resource, ?int $id = null, ?string $detail = null): void {
        query($this->db,
            'INSERT INTO auditoria (usuario,accion,recurso,recurso_id,detalle) VALUES (?,?,?,?,?)',
            [$user, $action, $resource, $id, $detail]
        );
    }

    public function list(array $filters): array {
        $where = [];
        $params = [];
        if (isset($filters['usuario']) && trim((string)$filters['usuario']) !== '') {
            $where[] = 'usuario=?';
            $params[] = strtolower(trim((string)$filters['usuario']));
        }
        if (isset($filters['accion']) && trim((string)$filters['accion']) !== '') {
            $where[] = 'accion=?';
            $params[] = trim((string)$filters['accion']);
        }
        $resourceId = $filters['reserva_id'] ?? $filters['recurso_id'] ?? null;
        if ($resourceId !== null && $resourceId !== '') {
            $id = filter_var($resourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new ApiError('ID de recurso inválido.');
            $where[] = 'recurso_id=?';
            $params[] = $id;
            if (isset($filters['reserva_id'])) $where[] = "recurso='reservas'";
        }
        foreach (['desde', 'hasta'] as $key) {
            if (empty($filters[$key])) continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$filters[$key]);
            if (!$date || $date->format('Y-m-d') !== $filters[$key]) throw new ApiError('Fecha de auditoría inválida.');
            $where[] = $key === 'desde' ? 'creado>=?' : 'creado<?';
            $params[] = $key === 'desde' ? $date->format('Y-m-d') : $date->modify('+1 day')->format('Y-m-d');
        }
        if (!empty($filters['before_id'])) {
            $before = filter_var($filters['before_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($before === false) throw new ApiError('Cursor de auditoría inválido.');
            $where[] = 'id<?';
            $params[] = $before;
        }
        $sql = 'SELECT id,usuario,accion,recurso,recurso_id,detalle,creado FROM auditoria';
        if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
        $sql .= ' ORDER BY id DESC LIMIT 100';
        return query($this->db, $sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
