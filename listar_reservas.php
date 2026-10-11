<?php
include "conexion.php";

$sql = "SELECT * FROM reservas ORDER BY fecha DESC, hora ASC";
$resultado = $conexion->query($sql);

$reservas = [];

while ($fila = $resultado->fetch_assoc()) {
    $reservas[] = $fila;
}

echo json_encode($reservas);
?>