# Aplicaciones web y registro de servicios: Arena 7

## Aplicación y arquitectura demostrable

El portal Arena 7 es una interfaz web para gestionar reservas de cuatro canchas. El navegador consume servicios HTTP que intercambian JSON con un backend PHP y guardan datos en MySQL. El modelo elegido es **REST/JSON** y el enfoque de desarrollo es **code-first**: las rutas y su comportamiento se implementaron en PHP y se documentaron después en el catálogo técnico. SOAP/XML no se usa. El sistema actual es un **monolito modular con una API REST**, complementado por un **ESB ligero independiente** para mediar las integraciones de DNI y QR de pasarela. Esto es una etapa de la SOA de cuatro capas propuesta en el informe; no equivale a cinco microservicios desplegados por separado ni a una SAGA completa.

El navegador llama, por ejemplo, a `GET /ubicacion.php` para mostrar la ubicación y a `POST /api.php/reservas/dni` para autocompletar el nombre. La segunda operación exige sesión y token CSRF. El servicio de reservas envía la consulta al ESB por `https://localhost:8444/esb.php/identidad/dni`, y el ESB consulta a Decolecta, valida la respuesta y devuelve únicamente el documento y el nombre. Así el navegador no conoce la credencial del proveedor. `POST /api.php/reservas/{id}/qr` también se media por el ESB hacia TAYPI, pero no puede generar cobros reales hasta disponer de una cuenta y credenciales de comercio. El Yape personal funciona con verificación manual y su QR no fija el monto al escanear.

La coordinación de reservas, pagos, notificaciones y auditoría ocurre dentro de PHP. Las operaciones de reserva y pago usan transacciones de MySQL. Los avisos por correo se insertan en una cola de trabajo de MySQL y un proceso separado los envía mediante Gmail SMTP o Resend; la respuesta al usuario no espera al correo. La auditoría se registra en la misma transacción de las acciones importantes, no en una cola del ESB. El ESB implementado cubre enrutamiento y mediación de dos proveedores; la orquestación SAGA y las colas del ESB descritas en el informe permanecen como diseño futuro.

## Registro y servicios consumidos

El panel **Catálogo de servicios** consume `GET /api.php/catalogo` y presenta nombre, función, operaciones y estado de los cinco servicios internos: Autenticación (SRV-001), Reserva de Cancha (SRV-002), Gestión de Pagos (SRV-003), Notificaciones (SRV-004) y Auditoría (SRV-005). DNI y ubicación son integraciones que apoyan el flujo, no otros servicios del inventario del informe. Los proveedores externos utilizados son Decolecta para nombre por DNI y Gmail SMTP o Resend para correo. Google Maps se usa mediante enlaces a una ubicación y rutas; no se consume su API de datos. TAYPI está conectado en código, pero permanece inactivo para cobros reales sin credenciales de comercio.

Un registro **UDDI** permite descubrir servicios publicados y consultar su descripción técnica, incluidos proveedor y contrato de acceso. El catálogo de Arena 7 cumple una función local de descubrimiento para el administrador, pero **no implementa el estándar UDDI**. Tampoco almacena todos los datos de los servicios ni ejecuta automáticamente sus operaciones: esas no son funciones del registro.

## Tecnologías y herramientas

| Tecnología o herramienta | Función en Arena 7 | Justificación |
| --- | --- | --- |
| HTML y CSS | Formulario, calendario, tablas y vistas del panel | Permiten una interfaz web accesible desde el navegador. |
| JavaScript `fetch` | Consumir endpoints REST y mostrar respuestas JSON | Actualiza DNI, ubicación y estados sin recargar toda la página. |
| PHP 8.2 | API, reglas de reservas, pagos, sesión y ESB ligero | Encaja con el sistema existente y permite separar clases de servicio. No se usa Laravel ni otro framework PHP. |
| Caddy y PHP FastCGI | Servir el portal y el ESB por HTTPS local | Cifra el transporte y aísla dos procesos PHP en puertos internos diferentes. |
| MySQL | Persistir usuarios, reservas, pagos, avisos y auditoría | Soporta transacciones y control de concurrencia para evitar reservas dobles. |
| PHPMailer y Gmail SMTP | Enviar confirmaciones por correo | Permite entrega autenticada con TLS y reintentos desde la cola. |
| Decolecta | Consultar el nombre asociado a un DNI | Fuente externa que evita digitar el nombre cuando está disponible. No prueba que el cliente sea el titular. |
| Postman | Probar métodos, cabeceras, estados y JSON de la API | La colección incluida reproduce solicitudes sin guardar claves ni DNI reales. |

## Seguridad aplicada

El portal y el ESB exigen HTTPS local. La API privada exige sesión del administrador; las operaciones que modifican datos requieren token CSRF. El ESB requiere una clave interna temporal compartida entre sus dos procesos PHP, que se genera al iniciar y no se publica en el navegador ni en GitHub. Las credenciales de Decolecta, correo y pasarela son variables del servidor. Las consultas privadas responden con `Cache-Control: no-store`. La información de DNI se envía a Decolecta para completar el nombre; esto debe explicarse al hablar de privacidad. El certificado local de Caddy sirve para `localhost`; un despliegue público requeriría un dominio y certificado público propios.

## Evidencias y secuencia de exposición

1. Abrir la aplicación en `https://localhost:8443/` y el **Catálogo de servicios**. Enseñar los cinco servicios y el estado `ESB operativo`.
2. En Postman, importar `docs/postman/Arena7.postman_collection.json`. Mostrar `GET /ubicacion.php` y la respuesta JSON. Luego mostrar `GET https://localhost:8444/esb.php/health`. Las respuestas reales, sin datos personales, están guardadas en `docs/evidencias/`.
3. En el panel, ingresar un DNI que el expositor esté autorizado a consultar y mostrar el `POST /api.php/reservas/dni` en la pestaña Red o en Postman tras iniciar sesión. Omitir o tapar DNI y nombre en las capturas que se compartan.
4. Crear una reserva de demostración solo con datos y horario de prueba. Mostrar el importe calculado por horario, el estado pendiente o confirmado según la verificación manual del pago, el aviso de correo y la auditoría. No presentar un QR comercial real sin credenciales de TAYPI.
5. Mostrar las capturas de código y pantallas en `evidencias/README.md` (carpeta local, fuera de GitHub porque algunas imágenes contienen información privada). Explicar REST, code-first, ESB ligero, cola de correo, MySQL, UDDI conceptual y medidas de seguridad.

La colección de Postman requiere completar el CAPTCHA manualmente e iniciar sesión antes de llamar rutas privadas. No contiene contraseña, token ni DNI real. Si el certificado local no está confiado en Postman, importar el certificado raíz local de Caddy; no desactivar la validación TLS.
