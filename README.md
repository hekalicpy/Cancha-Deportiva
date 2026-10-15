# Arena 7 - Sistema de Reservas

Proyecto web PHP y MySQL para gestionar reservas de Arena 7, con una capa de servicios en desarrollo.

## Estado actual

El portal conserva sus endpoints PHP originales. La nueva API de cinco servicios está implementada parcialmente y todavía no está integrada por completo al frontend. La API registra Yape/Plin para revisión manual, mientras que el formulario principal conserva su flujo de confirmación anterior; no hay validación bancaria automática. Consulta DNI y correo requieren cuentas y claves de sus proveedores. Esta copia no incluye datos de clientes ni usuarios de la base de datos.

## Requisitos

- PHP 8.2 con extensiones `mysqli` y `curl`.
- MySQL o MariaDB.

## Inicio local

1. Se necesita la base de datos existente `cancha_deportiva` con las tablas `usuarios`, `reservas`, `pagos` y `auditoria`. La migración actual es incremental, no un instalador desde cero. Configure las variables de entorno mostradas en `.env.example`; el programa no carga archivos `.env` automáticamente. Los endpoints que usa la pantalla principal ahora comparten la conexión y la sesión de `services/bootstrap.php`.
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

### Consulta de DNI

El botón «Buscar DNI» del formulario llama a `GET /api.php/reservas/dni?dni=12345678` desde una sesión autenticada. PHP consulta la [API DNI de Decolecta](https://decolecta.gitbook.io/docs/servicios/integrations-2) con un token guardado solo en el servidor; comprueba que el número devuelto coincida y completa el nombre. No completa teléfono ni otros datos. Una consulta de nombres **no demuestra que quien reserva sea el titular del DNI**: el operador debe revisar el documento. Si el proveedor no responde o no hay token, el formulario permite ingresar el nombre manualmente. Se retiraron los nombres de prueba que antes aparecían al buscar.

Decolecta [ofrece 1.000 solicitudes mensuales gratuitas](https://decolecta.com/), pero exige crear una cuenta y obtener un token. Regístrese en [Decolecta](https://decolecta.com/profile) y configure la variable de entorno `RENIEC_TOKEN` antes de iniciar PHP. Por ejemplo, en PowerShell:

```powershell
$env:RENIEC_TOKEN = 'TOKEN_OBTENIDO_EN_DECOLECTA'
php -S localhost:8000
```

Si PHP ya estaba en marcha, reinícielo después de configurar la variable. No ponga el token en el código, `.env.example` ni GitHub. `php tests/dni_integration.php` verifica la validación y el formato de respuesta sin enviar datos a Decolecta.

### Ubicación del local

El menú lateral del sistema incluye «Ubicación», que abre la información del local con «Ver ubicación» y «Cómo llegar». Estos enlaces también aparecen en la pantalla de acceso y el comprobante.
`GET /ubicacion.php` devuelve `nombre`, `local`, `direccion`, `latitud`, `longitud`, `mapa` y `como_llegar` como JSON público, sin sesión ni base de datos. Otros métodos devuelven 405.
La ubicación se centraliza en `services/VenueLocation.php` y también se utiliza en las notificaciones. «Ver ubicación» abre la ficha concreta de Arena 7 - Sede Colegio Melitón Carvajal en Av. Prol. Iquitos 2139, Lince. «Cómo llegar» usa las coordenadas del marcador de esa ficha (-12.084917, -77.0282236), verificadas en Google Maps el 11/10/2026; no realiza una búsqueda por texto. Estos enlaces no requieren clave API. Google Maps permite elegir el punto de partida. El marcador corresponde al local; no se ha verificado por separado el acceso vehicular o peatonal.
Si falla la carga, la interfaz ofrece reintentar. Esto no activa el envío de correo, que sigue requiriendo configurar su proveedor.

### Auditoría

El menú «Auditoría» está disponible para usuarios con rol `administrador`. Permite consultar los últimos eventos, filtrar por usuario, acción, ID de recurso y fechas, y cargar páginas anteriores. `GET /api.php/auditoria` requiere sesión de administrador y devuelve hasta 100 eventos por página; `before_id` sirve para paginar.

La pantalla principal registra los inicios de sesión y las creaciones, ediciones y cancelaciones de reservas. El actor se toma de la sesión, no del formulario. Las reservas se cancelan cambiando su estado a `Cancelado`, de modo que se conserva su ID y su historial; el listado operativo no muestra las canceladas. Los cambios de reserva y su evento de auditoría se confirman en la misma transacción. Las acciones de pago realizadas por la API también escriben eventos. La migración instala bloqueos de actualización y eliminación sobre `auditoria`.

Para probar el servicio sin modificar datos reales, ejecute `php tests/auditoria_integration.php`. La prueba utiliza tablas temporales de MySQL.

### Otros módulos

- Autenticación con sesión, CAPTCHA y roles.
- Reserva de cancha mediante la API de servicios.
- Gestión de pagos con confirmación administrativa.
- Notificaciones de confirmación y enlace de Google Maps.
- Auditoría persistente de inicios de sesión, reservas y pagos.

Las claves de proveedores externos nunca deben incorporarse al repositorio. Use variables de entorno locales.
