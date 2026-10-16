# Arena 7 - Sistema de Reservas

Proyecto web PHP y MySQL para gestionar reservas de Arena 7, con una capa de servicios en desarrollo.

## Estado actual

El portal conserva sus endpoints PHP originales. La nueva API de cinco servicios está implementada parcialmente y todavía no está integrada por completo al frontend. El Yape personal conserva el registro pendiente y confirmación manual. Además hay una opción independiente de pasarela QR que fija el importe en el servidor y confirma únicamente avisos firmados del proveedor; permanece inactiva hasta configurar credenciales de comercio. Consulta DNI y correo requieren cuentas y claves de sus proveedores. Esta copia no incluye datos de clientes ni usuarios de la base de datos.

## Requisitos

- PHP 8.2 con extensiones `mysqli` y `curl`.
- MySQL o MariaDB.
- Composer para instalar PHPMailer cuando se usa Gmail.

## Inicio local

1. Se necesita la base de datos existente `cancha_deportiva` con las tablas `usuarios`, `reservas`, `pagos` y `auditoria`. La migración actual es incremental, no un instalador desde cero. Configure las variables de entorno mostradas en `.env.example`; el programa no carga archivos `.env` automáticamente. Los endpoints que usa la pantalla principal ahora comparten la conexión y la sesión de `services/bootstrap.php`.
2. Ejecute la migración:

   ```powershell
   php bin/migrate.php
   ```

   Para el envío por Gmail, instale también las dependencias con `composer install`.

3. Inicie la aplicación con HTTPS (el primer inicio descarga Caddy desde su sitio oficial):

   ```powershell
   pwsh -File .\bin\start-https.ps1
   ```

4. La primera vez, ejecute `pwsh -File .\bin\confiar-certificado-https.ps1` y apruebe en Windows **solo** el certificado local de Caddy cuya huella muestra el script. Después abra `https://localhost:8443/`. El antiguo `http://localhost:8000/` responde únicamente con una redirección permanente a HTTPS. El certificado y la clave quedan en el perfil local de Windows; no se publican en GitHub. Si Windows no ha aprobado el certificado, el navegador mostrará una advertencia: no la omita, complete el paso de confianza.

El servidor PHP integrado (`php -S`) no ofrece TLS, así que no lo use para iniciar este proyecto. El script ejecuta PHP FastCGI solo en `127.0.0.1:9000` y sirve exclusivamente los recursos públicos; bloquea rutas internas como `services/`, `tests/` y `.env`. Para publicar en Internet hará falta un dominio y un certificado público válido en el servidor HTTPS; subir código a GitHub no publica la aplicación.

## Servicios

### Consulta de DNI

Al completar los 8 dígitos del DNI, el formulario llama automáticamente a `POST /api.php/reservas/dni` con el número en el cuerpo y un token CSRF. Espera medio segundo tras la última tecla para evitar consultas repetidas. PHP consulta la [API DNI de Decolecta](https://decolecta.gitbook.io/docs/servicios/integrations-2) con un token guardado solo en el servidor; comprueba que el número devuelto coincida y completa el nombre. En paralelo, `POST /api.php/reservas/contacto` busca el último celular que este sistema guardó en una reserva con ese DNI y lo sugiere solo si el campo está vacío. Esta consulta local funciona aunque falle Decolecta y nunca envía el celular a un tercero. El celular sugerido **no está verificado como actual**: el administrador debe confirmarlo con el cliente. Si no hay historial, se ingresa manualmente. La consulta de nombres **no demuestra que quien reserva sea el titular del DNI**: el operador debe revisar el documento. Si el proveedor no responde o no hay token, el formulario permite ingresar el nombre manualmente. Se retiraron los nombres de prueba que antes aparecían al buscar.

No existe una fuente pública fiable para completar el celular actual a partir de un DNI: [Checa tus líneas de OSIPTEL](https://www.osiptel.gob.pe/portal-del-usuario/noticias/evita-suplantaciones-y-fraudes-verifica-cuantas-lineas-moviles-estan-registradas-a-tu-nombre/) oculta los últimos cuatro dígitos, y los [niveles de consulta de RENIEC](https://identidad.reniec.gob.pe/documents/d/guest/requisitos) enumeran datos de identidad, no el número celular. No se usan repositorios de datos filtrados ni servicios que prometen descubrir teléfonos privados.

Solo la cuenta `PRIVATE_DATA_OWNER` con rol `administrador` puede iniciar sesión y acceder a DNI, reservas, pagos y auditoría. Los endpoints antiguos y nuevos aplican la misma regla. El DNI ya no sale en el enlace de WhatsApp ni en la URL de la petición del navegador; las respuestas privadas no se almacenan en caché. **Límite de privacidad:** Decolecta recibe el DNI para hacer la consulta, y quien administra el servidor o MySQL puede acceder a los registros. Si se publica el sistema fuera de `localhost`, use un certificado público válido, acceso restringido a la base de datos y copias de seguridad protegidas.

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

En la opción Yape, el formulario y la constancia pendiente muestran únicamente el área del código QR original proporcionado por el propietario. El correo de pago pendiente incorpora una copia recortada de ese mismo QR, sin nombre ni fondo adicional. El recorte conserva los píxeles del código; el QR personal **no fija el importe automáticamente**, por lo que el correo indica el monto que el cliente debe ingresar. El número **989518266** permanece como configuración del comercio mediante `YAPE_PHONE`, sin mostrarse en la interfaz de pago. El mensaje para compartir no incluye DNI ni datos de pago. La constancia imprimible tampoco incluye DNI, teléfono ni código de pago. El código de operación se puede dejar vacío mientras el pago está pendiente; para marcar como recibido un pago por Yape o Plin, el administrador debe registrarlo y comprobar el abono en su cuenta. No se admite reutilizar un código de operación vigente. El código por sí solo no prueba que se haya pagado. Al crear una reserva manual, el administrador puede marcar «Ya comprobé que recibí el pago»; si lo deja sin marcar, la reserva queda `Pendiente` durante 10 minutos. La constancia muestra explícitamente si sigue pendiente. Para Plin no hay QR ni número de destino configurados, por lo que no se envía un QR de Plin.

Un número de celular por sí solo no proporciona una API de Yape para consultar transferencias entrantes. Según la [ayuda oficial de Yape](https://www.yape.com.pe/preguntas-frecuentes/enviar-y-recibir-yapeos/como-cobrar-con-yape), el cobro directo se hace desde la aplicación usando número o QR y la confirmación se comprueba en la cuenta receptora. Por eso esta integración dirige el pago al número indicado y conserva la verificación manual; no afirma validar automáticamente transferencias.

Existe un [SDK PHP abierto de TAYPI](https://github.com/neoevsys/taypi-php) para [QR interoperables con Yape y Plin](https://docs.taypi.pe/). Este proyecto conecta directamente con la API documentada; el código de integración está preparado, pero los cobros reales requieren una cuenta de comercio y claves propias aún no configuradas. No use repositorios que simulan pagos o claves de terceros como prueba de una transferencia real.

### Pasarela QR con importe automático

El método «QR con monto automático (Yape o Plin)» es independiente del Yape personal: no modifica su número, QR ni cuenta. La tarifa es **S/ 40.00** entre las 08:00 y las 17:00, y **S/ 60.00** entre las 18:00 y las 22:00; se aplica a las cuatro canchas y a todos los medios de pago. El servidor calcula y valida el importe al crear la reserva. Tras guardar, `POST /api.php/reservas/{id}/qr` genera un QR único por reserva mediante la API de TAYPI, si se configura una cuenta de comercio. Usa una referencia estable como llave de idempotencia para que reintentar no cree un segundo cobro. La reserva permanece pendiente hasta recibir `payment.completed` en `POST /taypi_webhook.php` con firma HMAC válida, moneda e importe coincidentes. El aviso repetido no confirma dos veces. Un cobro después de vencer o cancelar la reserva queda marcado para revisión manual y posible devolución.

Este tipo de QR es el que permite mostrar el importe dentro de la billetera al escanear. La [ayuda de Yape para QR personales](https://www.yape.com.pe/preguntas-frecuentes/enviar-y-recibir-yapeos/49--cualquier-persona-puede-pagarme-con-qr) indica que el cliente ingresa el monto; los [QR de comercio documentados por Culqi](https://docs.culqi.com/es/documentacion/pagos-online/ordenes-de-pago/billetera-moviles/) sí lo muestran. No se debe modificar el QR personal ni crear uno que aparente fijar el importe sin estar respaldado por una pasarela real.

Para probarlo, abra una [cuenta sandbox de TAYPI](https://sandbox.taypi.pe/) y configure en el entorno de PHP `TAYPI_MODE=sandbox`, `TAYPI_PUBLIC_KEY`, `TAYPI_SECRET_KEY` y `TAYPI_WEBHOOK_SECRET`; reinicie PHP y ejecute `php bin/migrate.php`. **El QR sandbox es simulado y no acepta Yape real.** Para producción, TAYPI exige verificación del comercio, claves `live`, un CCI del titular y un webhook público HTTPS; `localhost` no puede recibir ese webhook desde Internet. Configure `TAYPI_MODE=live` solo después de probar el flujo completo y los costos del proveedor. [Requisitos y límites de ambientes](https://docs.taypi.pe/ambientes), [firma de avisos](https://docs.taypi.pe/webhooks), [tarifas](https://taypi.pe/precios/). El dinero de la pasarela se liquida según la cuenta bancaria del comercio registrada allí, no por el QR personal de Yape.
En Windows, `bin/start-https.ps1` carga esas cuatro variables desde las variables de usuario antes de iniciar o reiniciar PHP; no ponga las claves en el repositorio ni en el navegador.

`php tests/pasarela_integration.php` verifica importe, firma, idempotencia y cobro tardío usando tablas temporales; no envía solicitudes al proveedor. Falta una prueba real de extremo a extremo hasta que el comercio tenga credenciales propias. Nunca publique claves en GitHub.

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

### Notificaciones por correo

El formulario de reservas permite ingresar el correo del cliente. Se valida y guarda en la base de datos; no se envía el DNI en el mensaje. Cada aviso indica la cancha, el deporte, la fecha, la hora exacta de inicio y fin, el importe y el enlace a la ubicación exacta. El administrador elige si ya comprobó el pago al crear una reserva por Yape, Plin o efectivo. Si está comprobado, se prepara directamente el correo de confirmación; si sigue pendiente, se envía primero una solicitud que deja claro que la cancha aún no está confirmada. La solicitud por Yape lleva el QR incrustado en el correo; para efectivo y Plin no hay QR configurado. La pasarela independiente continúa pendiente hasta recibir el aviso firmado del proveedor. Al confirmar manualmente el pago se prepara un segundo correo que confirma la reserva. Si la confirmación ocurre antes de enviar el primer aviso, este se cancela para evitar un mensaje atrasado. Una reserva sin correo queda marcada `Sin correo`; si se añade antes del primer envío, pasa a `Pendiente`. El envío fallido se reintenta hasta tres veces, con esperas de uno y cinco minutos; después queda `Fallido` para revisión manual. Un destinatario inválido no se reintenta. El menú «Notificaciones» muestra al administrador el tipo de aviso, destinatario, estado, intentos y fecha de envío; también se puede consultar `GET /api.php/notificaciones?reserva_id=...`. `Enviado` indica que el proveedor aceptó el correo, no que el destinatario ya lo leyó o que llegó a su bandeja de entrada.

Para enviar mediante Gmail, active la [verificación en dos pasos y cree una contraseña de aplicación](https://support.google.com/mail/answer/185833). En Windows, ejecute `pwsh -File .\bin\configurar_gmail.ps1`: el programa pide la cuenta remitente y la contraseña de aplicación con entrada oculta, envía una prueba a esa misma cuenta y solo si Gmail la acepta guarda `GMAIL_ADDRESS` y `GMAIL_APP_PASSWORD` en las variables de entorno del usuario. No escriba la clave en el chat ni use la contraseña habitual de Google. Las variables de usuario no se publican en GitHub, pero los procesos del mismo usuario de Windows pueden leerlas; proteja la sesión del equipo. También puede configurar esas dos variables por otros medios seguros. Gmail usa SMTP cifrado en `smtp.gmail.com:465`; PHPMailer mantiene activada la verificación TLS. El correo del cliente es el destinatario, no la cuenta remitente. Si no se dispone de una contraseña de aplicación, Google puede impedir este método.

Resend sigue disponible como alternativa: requiere una [cuenta](https://resend.com/), una clave API de envío y un [dominio propio verificado](https://resend.com/docs/dashboard/domains/introduction). Configure `RESEND_API_KEY` y `MAIL_FROM` (por ejemplo, `reservas@su-dominio.com`). Si ambos proveedores están configurados, se usa Gmail. Reinicie PHP después de configurar las variables. Ejecute `php bin/notificaciones.php` para procesar la cola manualmente. En Windows, `pwsh -File .\bin\instalar_tarea_notificaciones.ps1` crea la tarea `Arena7-Notificaciones`, que ejecuta ese procesador cada minuto mientras el usuario de Windows tiene la sesión abierta. Si el equipo está apagado o la sesión está cerrada, el proceso no puede enviar hasta volver a ejecutarse.

`php tests/notificaciones_integration.php` comprueba que reservas de clientes distintos conserven destinatarios y mensajes independientes, además de la cola, confirmación y validación, con tablas temporales y sin enviar correos. Se puede establecer `TEST_NOTIFICATION_EMAIL` solo en la sesión de prueba para usar un destinatario concreto, sin escribirlo en el repositorio. Para una prueba real use una reserva de prueba con un correo que controle, confirme el pago cuando corresponda, ejecute el procesador y revise tanto el estado en el menú como la bandeja del destinatario. La tarea de Windows también se ejecuta cuando el portátil está funcionando con batería. Sin credenciales de envío, las notificaciones se conservan como `Pendiente` y no se afirma que se hayan entregado.
