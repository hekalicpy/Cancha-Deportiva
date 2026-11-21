# Respuestas HTTP verificadas

Las respuestas de esta carpeta se obtuvieron el 20/11/2026 desde el servidor local iniciado con `bin/start-https.ps1`. Ambos `GET` devolvieron HTTP 200 y `Content-Type: application/json` por HTTPS:

- `GET https://localhost:8443/ubicacion.php` → `GET-ubicacion.json`. La interfaz consume este endpoint mediante `fetch` para mostrar la ubicación y los enlaces.
- `GET https://localhost:8444/esb.php/health` → `GET-esb-health.json`. Demuestra que el proceso ESB responde de manera independiente.

Se comprobó además que `POST /esb.php/identidad/dni` sin clave interna devuelve HTTP 401 y que `GET /api.php/catalogo` sin sesión devuelve HTTP 401. Las llamadas internas autenticadas con entradas inválidas obtuvieron HTTP 422 para DNI y QR. Estas comprobaciones no consultaron DNI reales ni generaron cobros.

Después se ejecutó una consulta de DNI autorizada a través de `DniService → ESB → Decolecta` y se verificó que regresó un nombre no vacío, con `consultado=true` e `identidad_confirmada=false`. El documento y el nombre no se guardaron en esta evidencia. Esta lectura no generó una reserva.

Para mostrar un POST de DNI con respuesta de una persona real durante la exposición, use el formulario o la colección de Postman con un DNI autorizado y oculte el número y el nombre al guardar o compartir una captura. No guarde sesiones, contraseñas ni tokens en este repositorio.
