<?php
$conexion = new mysqli("localhost", "root", "", "cancha_deportiva");

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}
?>