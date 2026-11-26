<?php

namespace App\Services;

use App\Exceptions\LoginBloqueadoException;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthService
{
    /**
     * Login local.
     *
     * Uso:
     * - Proveedor
     * - Correo + contraseña
     *
     * Endpoint:
     * POST /v1/auth/login
     */
    public function login(array $credentials)
    {
        if (empty($credentials['email']) || empty($credentials['password'])) {
            throw new \Exception('Faltan credenciales');
        }

        $email = trim(strtolower($credentials['email']));
        $password = (string) $credentials['password'];

        $user = User::where('email', $email)->first();

        if (!$user) {
            throw new \Exception('Credenciales inválidas');
        }

        /*
         * Límite de intentos: si la cuenta está bloqueada no se evalúa la
         * contraseña (así un bloqueo no se puede "adivinar" durante el castigo).
         */
        $this->verificarBloqueo($user);

        /*
         * Validación de usuario activo.
         * Si active viene null por registros antiguos, se considera activo temporalmente.
         */
        if ($user->active !== null && (int) $user->active !== 1) {
            throw new \Exception('El usuario está inactivo');
        }

        /*
         * Este login local queda reservado para proveedores.
         * Los usuarios internos entran por Microsoft mediante /auth/msal.
         */
        if ($user->role !== 'proveedor') {
            throw new \Exception('Este login es solo para proveedores');
        }

        if (empty($user->password_hash)) {
            throw new \Exception('El usuario no tiene contraseña configurada');
        }

        /*
         * Validación compatible:
         * - Soporta hashes antiguos MD5.
         * - Soporta hashes nuevos BCRYPT.
         */
        if (!$this->passwordMatches($password, $user->password_hash)) {
            $this->registrarFallo($user);
            throw new \Exception('Credenciales inválidas');
        }

        $this->limpiarFallos($user);

        /*
         * Migración automática:
         * Si el usuario tenía password_hash en MD5 y logró iniciar sesión,
         * se reemplaza por hash seguro BCRYPT.
         */
        if ($this->isLegacyMd5Hash($user->password_hash)) {
            // La contraseña ya existía: solo se cambia el algoritmo, no se le aplica la política nueva.
            $user->password_hash = $this->hashPassword($password, null, false);
            $user->save();
        }

        return $this->buildSessionForUser($user, 'local');
    }

    /**
     * Login con Microsoft / Entra ID.
     *
     * Uso:
     * - pm_admin
     * - pm_user
     * - admin_portal
     * - admin_area
     * - usuario_area
     *
     * El frontend autentica contra Microsoft y envía el email.
     * Este método valida que el usuario exista en la 
     * y devuelve un JWT propio del backend.
     *
     * Endpoint:
     * POST /v1/auth/msal
     */
    public function loginWithMsal(array $payload)
    {
        $isLocalDemo = env('APP_ENV', 'production') === 'local'
            && filter_var(
                env('MSAL_LOCAL_DEMO', false),
                FILTER_VALIDATE_BOOLEAN
            );

        if (!$isLocalDemo) {
            throw new \RuntimeException(
                'El inicio de sesión Microsoft requiere validación del token de identidad en el backend.'
            );
        }

        if (empty($payload['email'])) {
            throw new \Exception('Email no proporcionado');
        }

        $email = trim(strtolower($payload['email']));

        $user = User::where('email', $email)->first();

        if (!$user) {
            throw new \Exception('Usuario interno no registrado en el portal');
        }

        $rolesInternosPermitidos = [
            'pm_admin',
            'pm_user',
            'admin_portal',
            'admin_area',
            'usuario_area',
            'auditor',
        ];

        if (!in_array($user->role, $rolesInternosPermitidos, true)) {
            throw new \Exception('El usuario no tiene permisos internos para acceder');
        }

        /*
         * Validación de usuario activo.
         * Si active viene null por registros antiguos, se considera activo temporalmente.
         */
        if ($user->active !== null && (int) $user->active !== 1) {
            throw new \Exception('El usuario está inactivo');
        }

        /*
         * Compatibilidad temporal:
         * Si es pm_user antiguo y no tiene área, se asigna área PM.
         */
        if ($user->role === 'pm_user' && empty($user->area)) {
            $user->area = 'pm';
        }

        /*
         * Si Microsoft entrega displayName y el usuario no lo tiene,
         * se actualiza el dato en la base.
         */
        if (
            !empty($payload['displayName']) &&
            empty($user->display_name)
        ) {
            $user->display_name = $payload['displayName'];
        }

        /*
         * Si active viene null por usuario antiguo, lo normalizamos a 1.
         */
        if ($user->active === null) {
            $user->active = 1;
        }

        if ($user->isDirty()) {
            $user->save();
        }

        return $this->buildSessionForUser($user, 'entra');
    }

    /**
     * Genera una sesión JWT para un usuario ya creado.
     *
     * Se usa para:
     * - login local proveedor
     * - login MSAL interno
     * - activación de proveedor por invitación
     */
    public function buildSessionForUser(User $user, string $authType): array
    {
        if (!in_array($authType, ['local', 'entra'], true)) {
            throw new \InvalidArgumentException(
                'Tipo de autenticación no válido.'
            );
        }

        if ($user->active !== null && (int) $user->active !== 1) {
            throw new \RuntimeException('El usuario está inactivo');
        }

        /*
         * Compatibilidad temporal para proveedores/usuarios antiguos.
         */
        if ($user->active === null) {
            $user->active = 1;
            $user->save();
        }

        $token = $this->generateToken($user);

        // Guarda el ingreso para mostrarlo en "Mi cuenta"; no debe impedir el inicio de sesión.
        try {
            app(CuentaService::class)->registrarIngreso($user);
        } catch (\Throwable $e) {
            error_log('No se registró el ingreso: ' . $e->getMessage());
        }

        return $this->buildAuthResponse($user, $token, $authType);
    }

    /*
    |--------------------------------------------------------------------------
    | Password helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Genera un hash seguro para contraseñas nuevas.
     *
     * Aplica la política de contraseñas, salvo que $validar sea false
     * (solo para re-hashear una contraseña que el usuario ya tenía).
     */
    public function hashPassword(string $password, ?string $email = null, bool $validar = true): string
    {
        if ($validar) {
            $this->validarPassword($password, $email);
        }

        $cost = (int) env('PASSWORD_BCRYPT_COST', 12);
        $cost = max(10, min($cost, 14));

        $hash = password_hash(
            $password,
            PASSWORD_BCRYPT,
            ['cost' => $cost]
        );

        if ($hash === false) {
            throw new \RuntimeException(
                'No fue posible proteger la contraseña.'
            );
        }

        return $hash;
    }

    /**
     * Política de contraseñas para cuentas nuevas o cambios de contraseña.
     *
     * - Largo mínimo configurable (PASSWORD_MIN_LENGTH, por defecto 10).
     * - Máximo 72 bytes (límite real de bcrypt; más allá se ignoraría).
     * - Al menos una letra y un número.
     * - No puede contener la parte local del correo ni ser una clave típica.
     *
     * @throws \InvalidArgumentException con un mensaje apto para mostrar.
     */
    public function validarPassword(string $password, ?string $email = null): void
    {
        $min = max(8, min((int) env('PASSWORD_MIN_LENGTH', 10), 64));

        if (mb_strlen($password) < $min) {
            throw new \InvalidArgumentException(
                'La contraseña debe tener al menos ' . $min . ' caracteres.'
            );
        }

        if (strlen($password) > 72) {
            throw new \InvalidArgumentException(
                'La contraseña no puede superar los 72 caracteres.'
            );
        }

        if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            throw new \InvalidArgumentException(
                'La contraseña debe incluir al menos una letra y un número.'
            );
        }

        $minuscula = mb_strtolower($password);

        if ($email) {
            $local = mb_strtolower(explode('@', $email)[0]);

            if (mb_strlen($local) >= 4 && mb_strpos($minuscula, $local) !== false) {
                throw new \InvalidArgumentException(
                    'La contraseña no puede contener su correo electrónico.'
                );
            }
        }

        $comunes = ['password', 'contrasena', 'contraseña', 'qwerty', '12345678', 'abcd1234', 'admin123', 'proveedor'];

        foreach ($comunes as $comun) {
            if (mb_strpos($minuscula, $comun) !== false) {
                throw new \InvalidArgumentException(
                    'La contraseña es demasiado común. Elija una menos predecible.'
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Límite de intentos de inicio de sesión
    |--------------------------------------------------------------------------
    |
    | Tras LOGIN_MAX_INTENTOS fallos seguidos (por defecto 5) la cuenta queda
    | bloqueada LOGIN_BLOQUEO_MINUTOS minutos (por defecto 15). Un inicio de
    | sesión correcto reinicia el contador. Requiere las columnas
    | users.intentos_fallidos y users.bloqueado_hasta
    | (php artisan seguridad:aplicar las crea).
    |
    */

    private static $columnasBloqueo = null;

    private function bloqueoDisponible(): bool
    {
        if (self::$columnasBloqueo === null) {
            self::$columnasBloqueo = Schema::hasColumn('users', 'intentos_fallidos')
                && Schema::hasColumn('users', 'bloqueado_hasta');
        }

        return self::$columnasBloqueo;
    }

    /**
     * Cambio de contraseña de un proveedor ya autenticado.
     *
     * Exige la contraseña actual y cuenta los errores en el mismo límite de
     * intentos del inicio de sesión, para que un token robado no sirva para
     * adivinar la clave. Avisa al correo del usuario del cambio.
     *
     * @throws \InvalidArgumentException con un mensaje apto para mostrar.
     * @throws LoginBloqueadoException si la cuenta está bloqueada por intentos.
     */
    public function cambiarPassword(User $user, string $actual, string $nueva): void
    {
        $this->verificarBloqueo($user);

        if (!$this->passwordMatches($actual, (string) $user->password_hash)) {
            $this->registrarFallo($user);

            throw new \InvalidArgumentException('La contraseña actual no es correcta.');
        }

        if ($actual === $nueva) {
            throw new \InvalidArgumentException('La contraseña nueva debe ser distinta de la actual.');
        }

        $hash = $this->hashPassword($nueva, $user->email);

        $this->limpiarFallos($user);

        DB::table('users')->where('id', $user->id)->update([
            'password_hash' => $hash,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        try {
            app(NotificacionService::class)->notificar(
                $user,
                null,
                'seguridad_contrasena',
                'Tu contraseña fue cambiada',
                'La contraseña de tu cuenta se cambió el ' . date('d-m-Y H:i') . '. Si no fuiste tú, contacta de inmediato al equipo del portal.'
            );
        } catch (\Throwable $e) {
            error_log('Aviso de cambio de contraseña no enviado: ' . $e->getMessage());
        }
    }

    private function verificarBloqueo(User $user): void
    {
        if (!$this->bloqueoDisponible() || empty($user->bloqueado_hasta)) {
            return;
        }

        $hasta = strtotime($user->bloqueado_hasta);

        if ($hasta !== false && $hasta > time()) {
            $minutos = max(1, (int) ceil(($hasta - time()) / 60));

            throw new LoginBloqueadoException(
                'Demasiados intentos fallidos. Por seguridad, la cuenta está bloqueada temporalmente. '
                . 'Intente nuevamente en ' . $minutos . ' minuto' . ($minutos === 1 ? '' : 's') . '.'
            );
        }

        // El bloqueo ya venció: se reinicia el contador.
        $this->limpiarFallos($user);
    }

    private function registrarFallo(User $user): void
    {
        if (!$this->bloqueoDisponible()) {
            return;
        }

        $max = max(3, (int) env('LOGIN_MAX_INTENTOS', 5));
        $minutos = max(1, (int) env('LOGIN_BLOQUEO_MINUTOS', 15));

        DB::table('users')->where('id', $user->id)->increment('intentos_fallidos');

        $intentos = (int) DB::table('users')->where('id', $user->id)->value('intentos_fallidos');

        if ($intentos >= $max) {
            DB::table('users')->where('id', $user->id)->update([
                'intentos_fallidos' => 0,
                'bloqueado_hasta'   => date('Y-m-d H:i:s', time() + $minutos * 60),
            ]);

            throw new LoginBloqueadoException(
                'Demasiados intentos fallidos. Por seguridad, la cuenta quedó bloqueada '
                . $minutos . ' minutos.'
            );
        }
    }

    private function limpiarFallos(User $user): void
    {
        if (!$this->bloqueoDisponible()) {
            return;
        }

        if ((int) $user->intentos_fallidos === 0 && empty($user->bloqueado_hasta)) {
            return;
        }

        DB::table('users')->where('id', $user->id)->update([
            'intentos_fallidos' => 0,
            'bloqueado_hasta'   => null,
        ]);
    }

    /**
     * Verifica contraseña contra el hash almacenado.
     *
     * Soporta:
     * - MD5 antiguo.
     * - BCRYPT nuevo.
     */
    public function passwordMatches(string $plainPassword, ?string $storedHash): bool
    {
        if (empty($storedHash)) {
            return false;
        }

        /*
         * Compatibilidad con hashes antiguos MD5.
         * MD5 tiene 32 caracteres hexadecimales.
         */
        if ($this->isLegacyMd5Hash($storedHash)) {
            return hash_equals(strtolower($storedHash), md5($plainPassword));
        }

        /*
         * Hash moderno BCRYPT.
         */
        return password_verify($plainPassword, $storedHash);
    }

    /**
     * Detecta si el hash guardado parece ser MD5 antiguo.
     */
    public function isLegacyMd5Hash(?string $hash): bool
    {
        return is_string($hash)
            && strlen($hash) === 32
            && ctype_xdigit($hash);
    }

    /**
     * Genera JWT propio del backend.
     */
    private function generateToken(User $user): string
    {
        $secret = trim((string) env('JWT_SECRET', ''));

        if (strlen($secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET debe contener al menos 32 caracteres.'
            );
        }

        $now = time();
        $ttl = (int) env('JWT_TTL_SECONDS', 3600);
        $ttl = max(300, min($ttl, 86400));

        $payload = [
            'iss' => 'portal-proveedores',
            'sub' => (string) $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'area' => $user->area,
            'active' => (int) $user->active,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Respuesta estándar para frontend.
     */
    private function buildAuthResponse(User $user, string $token, string $authType): array
    {
        return [
            'token' => $token,
            'user'  => [
                'id'           => (string) $user->id,
                'email'        => $user->email,
                'display_name' => $user->display_name,
                'displayName'  => $user->display_name,
                'role'         => $user->role,
                'area'         => $user->area,
                'active'       => (int) $user->active,
                'auth_type'    => $authType,
                'authType'     => $authType,
            ],
        ];
    }
}