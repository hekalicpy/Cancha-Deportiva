<?php
final class CustomerContactService {
    public function __construct(private mysqli $db) {}

    public function lookup(string $dni): array {
        if (!preg_match('/^\d{8}$/', $dni)) throw new ApiError('El DNI debe tener 8 dígitos.');
        $row = query($this->db,
            "SELECT telefono FROM reservas WHERE dni=? AND telefono<>'' ORDER BY id DESC LIMIT 1",
            [$dni]
        )->get_result()->fetch_assoc();
        $phone = trim((string)($row['telefono'] ?? ''));
        return ['telefono'=>preg_match('/^[+0-9 ()-]{9,20}$/', $phone) ? $phone : null];
    }
}
