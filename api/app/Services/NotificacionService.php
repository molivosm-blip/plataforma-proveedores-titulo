<?php

namespace App\Services;

use App\Models\Notificacion;
use App\Models\Proveedores;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Notificaciones del portal
|--------------------------------------------------------------------------
|
| Cada aviso queda guardado en la tabla notificaciones (campana del portal)
| y se envía por correo según MAIL_MODO:
| - smtp: se envía por SMTP (en la demostración, a Mailpit). Si el servidor
|   no responde, el correo queda en el registro para no perder el aviso.
| - log:  se escribe en storage/logs/correos_simulados.log.
|
*/
class NotificacionService
{
    protected $smtp;

    public function __construct(SmtpCorreoService $smtp)
    {
        $this->smtp = $smtp;
    }

    /**
     * Notifica a todos los usuarios activos de un área.
     * Para el área PM se notifica al solicitante del proveedor.
     */
    public function notificarArea(
        string $area,
        Proveedores $proveedor,
        string $tipo,
        string $titulo,
        string $mensaje,
        ?string $referencia = null
    ): int {
        if ($area === 'pm') {
            return $this->notificarSolicitante($proveedor, $tipo, $titulo, $mensaje, $referencia);
        }

        $usuarios = User::where('active', 1)
            ->where('area', $area)
            ->whereIn('role', ['admin_area', 'usuario_area'])
            ->get();

        foreach ($usuarios as $usuario) {
            $this->notificar($usuario, $proveedor, $tipo, $titulo, $mensaje, $referencia);
        }

        return $usuarios->count();
    }

    public function notificarSolicitante(
        Proveedores $proveedor,
        string $tipo,
        string $titulo,
        string $mensaje,
        ?string $referencia = null
    ): int {
        $usuario = $proveedor->created_by_user_id
            ? User::where('active', 1)->find($proveedor->created_by_user_id)
            : null;

        if (!$usuario) {
            return 0;
        }

        $this->notificar($usuario, $proveedor, $tipo, $titulo, $mensaje, $referencia);

        return 1;
    }

    public function notificarProveedor(
        Proveedores $proveedor,
        string $tipo,
        string $titulo,
        string $mensaje,
        ?string $referencia = null
    ): int {
        $usuario = $proveedor->proveedor_user_id
            ? User::where('active', 1)->find($proveedor->proveedor_user_id)
            : null;

        if (!$usuario) {
            return 0;
        }

        $this->notificar($usuario, $proveedor, $tipo, $titulo, $mensaje, $referencia);

        return 1;
    }

    public function notificar(
        User $usuario,
        ?Proveedores $proveedor,
        string $tipo,
        string $titulo,
        string $mensaje,
        ?string $referencia = null
    ): Notificacion {
        $notificacion = Notificacion::create([
            'user_id'       => $usuario->id,
            'proveedor_id'  => $proveedor ? $proveedor->id : null,
            'tipo'          => $tipo,
            'referencia'    => $referencia,
            'titulo'        => $titulo,
            'mensaje'       => $mensaje,
            'email_enviado' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // La campana siempre se actualiza; el correo respeta la preferencia del usuario.
        if (!$this->correoPermitido($usuario, $tipo)) {
            return $notificacion;
        }

        if ($this->enviarCorreo($usuario, $titulo, $mensaje)) {
            $notificacion->email_enviado = 1;
            $notificacion->save();
        }

        return $notificacion;
    }

    /**
     * Evita repetir un recordatorio con la misma referencia dentro del período indicado.
     */
    public function yaNotificado(string $tipo, string $referencia, int $horas): bool
    {
        return Notificacion::where('tipo', $tipo)
            ->where('referencia', $referencia)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - ($horas * 3600)))
            ->exists();
    }

    private function correoPermitido(User $usuario, string $tipo): bool
    {
        try {
            return app(CuentaService::class)->correoActivado((int) $usuario->id, $tipo);
        } catch (\Throwable $e) {
            // Ante un error al leer la preferencia se prefiere avisar.
            return true;
        }
    }

    private function enviarCorreo(User $usuario, string $asunto, string $mensaje): bool
    {
        if (empty($usuario->email)) {
            return false;
        }

        if (strtolower((string) env('MAIL_MODO', 'log')) === 'smtp') {
            try {
                $this->smtp->enviar(
                    $usuario->email,
                    '[Portal de Proveedores] ' . $asunto,
                    'Hola ' . ($usuario->display_name ?: $usuario->email) . ',' . "\n\n"
                        . $mensaje . "\n\n"
                        . 'Ingresa al Portal de Proveedores para revisar el detalle.' . "\n"
                        . (string) env('PORTAL_URL', 'http://localhost:5173')
                );

                return true;
            } catch (\Throwable $e) {
                error_log('Correo no enviado por SMTP, queda en el registro: ' . $e->getMessage());
            }
        }

        return $this->registrarCorreo($usuario, $asunto, $mensaje);
    }

    private function registrarCorreo(User $usuario, string $asunto, string $mensaje): bool
    {
        $registro = implode(PHP_EOL, [
            '----- ' . date('Y-m-d H:i:s') . ' -----',
            'Para: ' . $usuario->email,
            'Asunto: [Portal de Proveedores] ' . $asunto,
            '',
            $mensaje,
            '',
        ]);

        $dir = storage_path('logs');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'correos_simulados.log',
            $registro . PHP_EOL,
            FILE_APPEND | LOCK_EX
        ) !== false;
    }
}
