<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Recuperación de clave de proveedores
|--------------------------------------------------------------------------
|
| Flujo: el proveedor pide el enlace con su correo; el portal responde
| siempre lo mismo (exista o no la cuenta, para no revelar qué correos
| están registrados) y, si corresponde, envía un enlace de un solo uso
| que vence en 60 minutos. El token se guarda como hash SHA-256: aunque
| alguien lea la base de datos no puede usarlo.
|
| Solo para proveedores. Los usuarios internos entran con Microsoft.
|
| Requiere la tabla recuperacion_password (php artisan mejoras:aplicar).
|
*/
class RecuperacionPasswordService
{
    const TABLA = 'recuperacion_password';

    protected $smtp;
    protected $auth;

    public function __construct(SmtpCorreoService $smtp, AuthService $auth)
    {
        $this->smtp = $smtp;
        $this->auth = $auth;
    }

    public function disponible(): bool
    {
        return Schema::hasTable(self::TABLA);
    }

    private function vigenciaMinutos(): int
    {
        return max(5, (int) env('RECUPERACION_VIGENCIA_MINUTOS', 60));
    }

    private function maxSolicitudesPorHora(): int
    {
        return max(1, (int) env('RECUPERACION_MAX_POR_HORA', 3));
    }

    /**
     * Crea el enlace y lo envía. Devuelve true si se generó un enlace
     * (el controlador igual responde lo mismo en todos los casos).
     */
    public function solicitar(string $email): bool
    {
        if (!$this->disponible()) {
            return false;
        }

        $user = User::where('email', trim(strtolower($email)))->first();

        if (!$user || $user->role !== 'proveedor' || (int) $user->active !== 1) {
            return false;
        }

        $reciente = date('Y-m-d H:i:s', time() - 3600);
        $pedidas = DB::table(self::TABLA)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $reciente)
            ->count();

        if ($pedidas >= $this->maxSolicitudesPorHora()) {
            return false;
        }

        $token = bin2hex(random_bytes(32));

        // Un solo enlace vigente por cuenta: el anterior deja de servir.
        DB::table(self::TABLA)
            ->where('user_id', $user->id)
            ->whereNull('usado_at')
            ->update(['usado_at' => date('Y-m-d H:i:s')]);

        DB::table(self::TABLA)->insert([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $token),
            'expira_at'  => date('Y-m-d H:i:s', time() + $this->vigenciaMinutos() * 60),
            'usado_at'   => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $enlace = rtrim((string) env('PORTAL_URL', env('FRONTEND_URL', 'http://localhost:5173')), '/')
            . '/restablecer-clave?token=' . $token;

        $asunto = '[Portal de Proveedores] Recuperación de clave';
        $cuerpo = 'Hola ' . ($user->display_name ?: $user->email) . ",\n\n"
            . "Recibimos una solicitud para cambiar tu clave del Portal de Proveedores.\n"
            . 'Usa este enlace dentro de los próximos ' . $this->vigenciaMinutos() . " minutos:\n\n"
            . $enlace . "\n\n"
            . 'Si no fuiste tú, ignora este mensaje: tu clave actual sigue vigente.';

        $this->enviar($user, $asunto, $cuerpo);

        return true;
    }

    private function enviar(User $user, string $asunto, string $cuerpo): void
    {
        if (strtolower((string) env('MAIL_MODO', 'log')) === 'smtp') {
            try {
                $this->smtp->enviar($user->email, $asunto, $cuerpo);

                return;
            } catch (\Throwable $e) {
                error_log('Correo de recuperación no enviado por SMTP: ' . $e->getMessage());
            }
        }

        // Sin SMTP el mensaje queda en el registro local, igual que los demás avisos.
        // El enlace NO se guarda en el registro compartido de avisos: solo en este archivo.
        $dir = storage_path('logs');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'correos_simulados.log',
            implode(PHP_EOL, ['----- ' . date('Y-m-d H:i:s') . ' -----', 'Para: ' . $user->email, 'Asunto: ' . $asunto, '', $cuerpo, '']) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Cambia la clave con el token recibido por correo.
     *
     * @throws \InvalidArgumentException con un mensaje apto para mostrar.
     */
    public function restablecer(string $token, string $nuevaClave): void
    {
        if (!$this->disponible()) {
            throw new \InvalidArgumentException('La recuperación de clave no está habilitada.');
        }

        $registro = DB::table(self::TABLA)
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (
            !$registro
            || $registro->usado_at !== null
            || strtotime((string) $registro->expira_at) < time()
        ) {
            throw new \InvalidArgumentException('El enlace no es válido o ya venció. Solicita uno nuevo.');
        }

        $user = User::find($registro->user_id);

        if (!$user || $user->role !== 'proveedor' || (int) $user->active !== 1) {
            throw new \InvalidArgumentException('El enlace no es válido o ya venció. Solicita uno nuevo.');
        }

        // Aplica la política de claves (largo, letra y número, no el correo). Lanza si no cumple.
        $hash = $this->auth->hashPassword($nuevaClave, $user->email);

        DB::table('users')->where('id', $user->id)->update(['password_hash' => $hash]);

        if (Schema::hasColumn('users', 'intentos_fallidos') && Schema::hasColumn('users', 'bloqueado_hasta')) {
            DB::table('users')->where('id', $user->id)->update([
                'intentos_fallidos' => 0,
                'bloqueado_hasta'   => null,
            ]);
        }

        DB::table(self::TABLA)->where('id', $registro->id)->update(['usado_at' => date('Y-m-d H:i:s')]);
    }
}
