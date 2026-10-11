# Arena 7 - Sistema de Reservas

Proyecto web PHP y MySQL para gestionar reservas de Arena 7, con una capa de servicios en desarrollo.

## Estado actual

El portal conserva sus endpoints PHP originales. La nueva API de cinco servicios está implementada parcialmente y todavía no está integrada por completo al frontend. Yape/Plin se registran para revisión manual; no hay validación bancaria automática. Consulta DNI y correo requieren cuentas y claves de sus proveedores. Esta copia no incluye datos de clientes ni usuarios de la base de datos.

## Requisitos

- PHP 8.2 con extensiones `mysqli` y `curl`.
- MySQL o MariaDB.

## Inicio local

1. Se necesita la base de datos existente `cancha_deportiva` con las tablas `usuarios`, `reservas`, `pagos` y `auditoria`. La migración actual es incremental, no un instalador desde cero. Configure las variables de entorno mostradas en `.env.example`; el programa no carga archivos `.env` automáticamente. Los endpoints originales aún usan `conexion.php`.
2. Ejecute la migración:

   ```powershell
   php bin/migrate.php
   ```

3. Inicie el servidor:

   ```powershell
   php -S localhost:8000
   ```

4. Abra `http://localhost:8000`.

## Servicios

- Autenticación con sesión, CAPTCHA y roles.
- Reserva de cancha y consulta de identidad DNI mediante proveedor RENIEC configurable.
- Gestión de pagos con confirmación administrativa.
- Notificaciones de confirmación y enlace de Google Maps.
- Auditoría persistente de inicios de sesión, reservas y pagos.

Las claves de proveedores externos nunca deben incorporarse al repositorio. Use variables de entorno locales.
