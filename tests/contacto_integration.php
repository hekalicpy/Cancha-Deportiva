<?php
require __DIR__.'/../services/bootstrap.php';

// La tabla temporal evita leer o modificar teléfonos reales durante la prueba.
$definition = $db->query('SHOW CREATE TABLE `reservas`')->fetch_row()[1];
$db->query(str_replace('CREATE TABLE `reservas`', 'CREATE TEMPORARY TABLE `reservas`', $definition));

$contact = new CustomerContactService($db);
if ($contact->lookup('12345678')['telefono'] !== null) throw new RuntimeException('Se inventó un celular sin historial.');

foreach ([['12345678', '911111111'], ['87654321', '922222222'], ['12345678', '933333333']] as [$dni, $phone]) {
    query($db,
        "INSERT INTO reservas (dni,cliente,telefono,cancha,fecha,hora,pago,codigo_pago,monto,detalle,email,usuario,identidad) VALUES (?, 'Cliente de prueba', ?, '1', '2026-10-20', '08', 'Efectivo', '', 40, '', '', 'prueba@example.invalid', 'Manual')",
        [$dni, $phone]
    );
}

if ($contact->lookup('12345678')['telefono'] !== '933333333') throw new RuntimeException('No se sugirió el último celular del DNI.');
if ($contact->lookup('87654321')['telefono'] !== '922222222') throw new RuntimeException('Se cruzaron celulares entre DNI.');
try {
    $contact->lookup('123');
    throw new RuntimeException('Se aceptó un DNI incompleto.');
} catch (ApiError $e) {
    if ($e->status !== 422) throw $e;
}

echo "Contacto: último celular, aislamiento por DNI y validación OK.\n";
