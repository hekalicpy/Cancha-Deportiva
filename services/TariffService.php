<?php
final class TariffService {
    public static function amount(string $hour): string {
        if (in_array($hour, ['08','09','10','11','12','13','14','15','16'], true)) return '40.00';
        if (in_array($hour, ['18','19','20','21'], true)) return '60.00';
        throw new ApiError('Horario sin tarifa configurada.');
    }

    public static function validate(string $hour, string $amount): void {
        $expected = self::amount($hour);
        if (!is_numeric($amount) || (float)$amount !== (float)$expected) {
            throw new ApiError("El monto para este horario es S/ $expected.");
        }
    }
}
