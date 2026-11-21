<?php
final class ServiceCatalog {
    public static function describe(): array {
        return [
            'modelo'=>'REST/JSON',
            'enfoque'=>'Code-first',
            'integracion'=>'ESB ligero por HTTPS para consulta DNI y generación de QR de pasarela; cola en MySQL para correo',
            'esb_operativo'=>EsbClient::healthy(),
            'registro'=>'Catálogo técnico local para descubrir rutas. No es un registro UDDI.',
            'servicios'=>[
                ['id'=>'SRV-001','nombre'=>'Autenticación','funcion'=>'Validar acceso y sesión del administrador',
                    'operaciones'=>['GET /api.php/auth/captcha','POST /api.php/auth/login','GET /api.php/auth/me','POST /api.php/auth/logout'],
                    'estado'=>'Operativo'],
                ['id'=>'SRV-002','nombre'=>'Reserva de cancha','funcion'=>'Consultar, crear, modificar y cancelar reservas; autocompletar DNI',
                    'operaciones'=>['GET /api.php/reservas','POST /api.php/reservas','PUT /api.php/reservas/{id}','DELETE /api.php/reservas/{id}','POST /api.php/reservas/dni'],
                    'estado'=>cfg('RENIEC_TOKEN') !== '' ? 'Operativo con consulta DNI' : 'Reservas operativas; DNI sin credencial'],
                ['id'=>'SRV-003','nombre'=>'Gestión de pagos','funcion'=>'Registrar pagos y confirmar manualmente los verificados; QR comercial opcional',
                    'operaciones'=>['GET /api.php/pagos','POST /api.php/pagos/{id}/confirmar','POST /api.php/reservas/{id}/qr'],
                    'estado'=>TaypiGateway::configured() ? 'Manual y pasarela configurada' : 'Manual operativo; pasarela sin credenciales'],
                ['id'=>'SRV-004','nombre'=>'Notificaciones','funcion'=>'Consultar avisos y enviar correos desde la cola de trabajo',
                    'operaciones'=>['GET /api.php/notificaciones','Procesador interno: bin/notificaciones.php'],
                    'estado'=>NotificationService::provider() !== '' ? 'Proveedor configurado' : 'Cola operativa; envío sin credenciales'],
                ['id'=>'SRV-005','nombre'=>'Auditoría','funcion'=>'Registrar eventos de acceso, reservas y pagos; consultar historial',
                    'operaciones'=>['GET /api.php/auditoria','Escritura interna desde operaciones del sistema'],
                    'estado'=>'Operativo'],
            ],
        ];
    }
}
