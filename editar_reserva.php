<?php
include "conexion.php";

$id = $_POST["id"];
$dni = $_POST["dni"];
$cliente = $_POST["cliente"];
$telefono = $_POST["telefono"];
$cancha = $_POST["cancha"];
$fecha = $_POST["fecha"];
$hora = $_POST["hora"];
$pago = $_POST["pago"];
$codigoPago = $_POST["codigoPago"];
$monto = $_POST["monto"];
$detalle = $_POST["detalle"];
$usuario = $_POST["usuario"];

$sql = "UPDATE reservas SET
dni='$dni',
cliente='$cliente',
telefono='$telefono',
cancha='$cancha',
fecha='$fecha',
hora='$hora',
pago='$pago',
codigo_pago='$codigoPago',
monto='$monto',
detalle='$detalle',
usuario='$usuario'
WHERE id='$id'";

if ($conexion->query($sql) === TRUE) {
    echo "ok";
} else {
    echo "error: " . $conexion->error;
}
?>