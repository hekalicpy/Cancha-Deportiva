<?php
include "conexion.php";

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

$sql = "INSERT INTO reservas 
(dni, cliente, telefono, cancha, fecha, hora, pago, codigo_pago, monto, detalle, usuario)
VALUES 
('$dni', '$cliente', '$telefono', '$cancha', '$fecha', '$hora', '$pago', '$codigoPago', '$monto', '$detalle', '$usuario')";

if ($conexion->query($sql) === TRUE) {
    echo "ok";
} else {
    echo "error: " . $conexion->error;
}
?>