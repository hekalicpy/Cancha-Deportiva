<?php
require __DIR__.'/../services/bootstrap.php';

$dni = '12345678';
$person = DniService::normalize(['document_number'=>$dni, 'full_name'=>'APELLIDO NOMBRE'], $dni);
if ($person['nombre'] !== 'APELLIDO NOMBRE' || $person['identidad_confirmada'] !== false) {
    throw new RuntimeException('La respuesta del proveedor no se interpretó correctamente.');
}
try {
    DniService::normalize(['document_number'=>'87654321', 'full_name'=>'OTRA PERSONA'], $dni);
    throw new RuntimeException('Se aceptó una respuesta para otro DNI.');
} catch (ApiError $e) {
    if ($e->status !== 502) throw $e;
}
try {
    (new DniService())->lookup('123');
    throw new RuntimeException('Se aceptó un DNI inválido.');
} catch (ApiError $e) {
    if ($e->status !== 422) throw $e;
}
if (cfg('RENIEC_TOKEN') === '') {
    try {
        (new DniService())->lookup($dni);
        throw new RuntimeException('La consulta sin token debía rechazarse.');
    } catch (ApiError $e) {
        if ($e->status !== 503) throw $e;
    }
}
echo "DNI: formato, coincidencia y ausencia de token OK.\n";
