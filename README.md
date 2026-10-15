# Arena 7 - Sistema de Reservas

Proyecto web PHP y MySQL para gestionar reservas de Arena 7, con una capa de servicios en desarrollo.

## Estado actual

El portal conserva sus endpoints PHP originales. La nueva API de cinco servicios está implementada parcialmente y todavía no está integrada por completo al frontend. Yape/Plin se registran como pendientes y solo el propietario puede confirmar manualmente la recepción del dinero; no hay validación bancaria automática. Consulta DNI y correo requieren cuentas y claves de sus proveedores. Esta copia no incluye datos de clientes ni usuarios de la base de datos.

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

Al completar los 8 dígitos del DNI, el formulario llama automáticamente a `POST /api.php/reservas/dni` con el número en el cuerpo y un token CSRF. Espera medio segundo tras la última tecla para evitar consultas repetidas. PHP consulta la [API DNI de Decolecta](https://decolecta.gitbook.io/docs/servicios/integrations-2) con un token guardado solo en el servidor; comprueba que el número devuelto coincida y completa el nombre. No completa teléfono ni otros datos. Una consulta de nombres **no demuestra que quien reserva sea el titular del DNI**: el operador debe revisar el documento. Si el proveedor no responde o no hay token, el formulario permite ingresar el nombre manualmente. Se retiraron los nombres de prueba que antes aparecían al buscar.

Solo la cuenta `PRIVATE_DATA_OWNER` con rol `administrador` puede iniciar sesión y acceder a DNI, reservas, pagos y auditoría. Los endpoints antiguos y nuevos aplican la misma regla. El DNI ya no sale en el enlace de WhatsApp ni en la URL de la petición del navegador; las respuestas privadas no se almacenan en caché. **Límite de privacidad:** Decolecta recibe el DNI para hacer la consulta, y quien administra el servidor o MySQL puede acceder a los registros. Si se publica el sistema fuera de `localhost`, configure HTTPS, acceso restringido a la base de datos y copias de seguridad protegidas.

Decolecta [ofrece una modalidad gratuita](https://decolecta.com/), pero exige crear una cuenta y obtener un token. Revise el límite de consultas vigente en su perfil de Decolecta. Regístrese en [Decolecta](https://decolecta.com/profile) y configure la variable de entorno `RENIEC_TOKEN` antes de iniciar PHP. Por ejemplo, en PowerShell:

```powershell
$env:RENIEC_TOKEN = 'TOKEN_OBTENIDO_EN_DECOLECTA'
php -S localhost:8000
```

Si PHP ya estaba en marcha, reinícielo después de configurar la variable. No ponga el token en el código, `.env.example` ni GitHub. `php tests/dni_integration.php` verifica la validación y el formato de respuesta sin enviar datos a Decolecta.
En algunas instalaciones de PHP para Windows, cURL necesita un paquete de certificados CA. Configure `CA_BUNDLE_PATH` con la ruta de un [paquete CA de curl](https://curl.se/docs/caextract.html) fuera del directorio web; la aplicación mantiene activada la verificación TLS.

### Ubicación del local

El menú lateral del sistema incluye «Ubicación», que abre la información del local con «Ver ubicación» y «Cómo llegar». Estos enlaces también aparecen en la pantalla de acceso y el comprobante.
`GET /ubicacion.php` devuelve `nombre`, `local`, `direccion`, `latitud`, `longitud`, `mapa` y `como_llegar` como JSON público, sin sesión ni base de datos. Otros métodos devuelven 405.
La ubicación se centraliza en `services/VenueLocation.php` y también se utiliza en las notificaciones. «Ver ubicación» abre la ficha concreta de Arena 7 - Sede Colegio Melitón Carvajal en Av. Prol. Iquitos 2139, Lince. «Cómo llegar» usa las coordenadas del marcador de esa ficha (-12.084917, -77.0282236), verificadas en Google Maps el 11/10/2026; no realiza una búsqueda por texto. Estos enlaces no requieren clave API. Google Maps permite elegir el punto de partida. El marcador corresponde al local; no se ha verificado por separado el acceso vehicular o peatonal.
Si falla la carga, la interfaz ofrece reintentar. Esto no activa el envío de correo, que sigue requiriendo configurar su proveedor.

### Pagos Yape y Plin

Los pagos por Yape se indican al número **989518266** (configurable mediante `YAPE_PHONE`). El formulario muestra el destino y permite copiarlo; la constancia pendiente y el mensaje para compartir incluyen el número y el monto, sin DNI ni referencia privada. El código de operación ingresado por el administrador es solo una referencia. La reserva queda `Pendiente` durante 15 minutos y el botón 💰 permite confirmarla **únicamente después de revisar el abono en la cuenta del comercio**. Un código escrito en el formulario no prueba que se haya pagado. La constancia muestra explícitamente si sigue pendiente. Para Plin no se anuncia ningún número de destino.

Un número de celular por sí solo no proporciona una API de Yape para consultar transferencias entrantes. Según la [ayuda oficial de Yape](https://www.yape.com.pe/preguntas-frecuentes/enviar-y-recibir-yapeos/como-cobrar-con-yape), el cobro directo se hace desde la aplicación usando número o QR y la confirmación se comprueba en la cuenta receptora. Por eso esta integración dirige el pago al número indicado y conserva la verificación manual; no afirma validar automáticamente transferencias.

Existe un [SDK PHP abierto de TAYPI](https://github.com/neoevsys/taypi-php) para [QR interoperables con Yape y Plin](https://docs.taypi.pe/). El código del SDK es libre, pero los cobros reales requieren una cuenta de comercio, claves API y condiciones comerciales del proveedor. Todavía no se han configurado esas claves ni una pasarela de cobro automático en este proyecto. No use repositorios que simulan pagos o claves de terceros como prueba de una transferencia real.

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
