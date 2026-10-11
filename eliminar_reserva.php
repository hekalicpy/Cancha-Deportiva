<?php
include "conexion.php";

$id = $_POST["id"];

$sql = "DELETE FROM reservas WHERE id='$id'";

if ($conexion->query($sql) === TRUE) {
    echo "ok";
} else {
    echo "error: " . $conexion->error;
}
?>