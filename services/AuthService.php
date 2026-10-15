<?php
class AuthService {
    public function __construct(private mysqli $db) {}
    public function captcha(): array {
        $a=random_int(1,9);$b=random_int(1,9);$_SESSION['captcha']=['answer'=>$a+$b,'expires'=>time()+300];return ['pregunta'=>"CAPTCHA: ¿Cuánto es $a + $b?"];
    }
    public function login(array $data): array {
        $email=strtolower(trim($data['correo']??''));
        $failed=query($this->db,"SELECT COUNT(*) n FROM auditoria WHERE usuario=? AND accion='LOGIN_FAIL' AND creado>DATE_SUB(NOW(),INTERVAL 15 MINUTE)",[$email])->get_result()->fetch_assoc()['n'];
        if($failed>=10) throw new ApiError('Demasiados intentos. Intente en 15 minutos.',429);
        $captcha=$_SESSION['captcha']??null;unset($_SESSION['captcha']);
        $u=query($this->db,'SELECT * FROM usuarios WHERE correo=?',[$email])->get_result()->fetch_assoc();
        $valid=$captcha&&$captcha['expires']>=time()&&(string)$captcha['answer']===(string)($data['captcha']??'');
        if(!$valid||!$u||!password_verify($data['clave']??'',$u['password'])) {
            (new AuditService($this->db))->record($email,'LOGIN_FAIL','usuarios',$u['id']??null);throw new ApiError('Correo, contraseña o CAPTCHA incorrectos.',401);
        }
        if($u['rol']!=='administrador'||!hash_equals(strtolower(trim(cfg('PRIVATE_DATA_OWNER','admin@arena7.com'))),$email)) {
            (new AuditService($this->db))->record($email,'LOGIN_FAIL','usuarios',(int)$u['id']);
            throw new ApiError('Esta cuenta no tiene acceso a los datos privados.',403);
        }
        session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['last_seen']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));
        (new AuditService($this->db))->record($email,'LOGIN_OK','usuarios',(int)$u['id']);return ['user'=>$this->requireUser(),'csrf'=>$_SESSION['csrf']];
    }
    public function requireUser(): array {
        if(empty($_SESSION['user_id'])||time()-($_SESSION['last_seen']??0)>7200) throw new ApiError('Inicie sesión para continuar.',401);
        $u=query($this->db,'SELECT id,correo,rol FROM usuarios WHERE id=?',[$_SESSION['user_id']])->get_result()->fetch_assoc();
        if(!$u) throw new ApiError('Sesión inválida.',401);$_SESSION['last_seen']=time();return $u;
    }
    public function privateData(): array {
        $user=$this->requireUser();
        $owner=strtolower(trim(cfg('PRIVATE_DATA_OWNER','admin@arena7.com')));
        if($user['rol']!=='administrador'||!hash_equals($owner,strtolower($user['correo']))) {
            throw new ApiError('Solo el propietario puede acceder a los datos privados.',403);
        }
        return $user;
    }
    public function csrf(): void {if(!hash_equals($_SESSION['csrf']??'',$_SERVER['HTTP_X_CSRF_TOKEN']??'')||empty($_SESSION['csrf'])) throw new ApiError('Solicitud no autorizada.',403);}
    public function admin(): void {if($this->requireUser()['rol']!=='administrador') throw new ApiError('Solo disponible para administradores.',403);}
}
