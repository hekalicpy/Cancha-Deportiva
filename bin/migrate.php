<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../services/bootstrap.php';
foreach(['email'=>"VARCHAR(254) NOT NULL DEFAULT ''",'deporte'=>"VARCHAR(20) NOT NULL DEFAULT 'Fútbol'",'identidad'=>"VARCHAR(20) NOT NULL DEFAULT 'Manual'",'hold_until'=>'DATETIME NULL'] as $name=>$definition) {
    $found=$db->query("SHOW COLUMNS FROM reservas LIKE '".$db->real_escape_string($name)."'")->num_rows;
    if(!$found) $db->query("ALTER TABLE reservas ADD COLUMN $name $definition");
}
$db->query("CREATE TABLE IF NOT EXISTS notificaciones (id INT AUTO_INCREMENT PRIMARY KEY,reserva_id INT NOT NULL UNIQUE,email VARCHAR(254) NOT NULL,mensaje TEXT NOT NULL,estado VARCHAR(30) NOT NULL,intentos INT NOT NULL DEFAULT 0,ultimo_error TEXT NULL,ultimo_intento DATETIME NULL,creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
foreach(query($db,'SELECT id,password FROM usuarios')->get_result()->fetch_all(MYSQLI_ASSOC) as $u) {
    if(password_get_info($u['password'])['algo']===null) query($db,'UPDATE usuarios SET password=? WHERE id=?',[password_hash($u['password'],PASSWORD_DEFAULT),$u['id']]);
}
foreach(['UPDATE','DELETE'] as $verb) {
    $name='auditoria_no_'.strtolower($verb);
    if(!query($db,'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?',[$name])->get_result()->num_rows) $db->query("CREATE TRIGGER $name BEFORE $verb ON auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Auditoria: solo inserciones permitidas'");
}
echo "Migración completada. Se conservaron reservas y usuarios.\n";
