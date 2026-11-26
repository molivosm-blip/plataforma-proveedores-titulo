<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
| Crea (o reactiva) un usuario interno. Sirve para registrar al primer
| administrador en una base nueva, ya que los usuarios internos entran con
| Microsoft Entra ID y no tienen clave en el portal.
|
| Uso: php artisan usuarios:crear-interno correo@empresa.cl "Nombre Apellido" admin_portal
|      php artisan usuarios:crear-interno ana@empresa.cl "Ana Pérez" usuario_area --area=contraloria
*/
class CrearUsuarioInterno extends Command
{
    protected $signature = 'usuarios:crear-interno {email} {nombre} {rol} {--area=}';

    protected $description = 'Crea un usuario interno (autenticado con Microsoft Entra ID)';

    private const ROLES = ['pm_admin', 'pm_user', 'admin_portal', 'admin_area', 'usuario_area', 'auditor'];

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $rol = $this->argument('rol');
        $area = $this->option('area') ?: null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');
            return 1;
        }

        if (!in_array($rol, self::ROLES, true)) {
            $this->error('Rol inválido. Use uno de: ' . implode(', ', self::ROLES));
            return 1;
        }

        if ($rol === 'pm_user' && !$area) {
            $area = 'pm';
        }

        if (in_array($rol, ['admin_area', 'usuario_area'], true) && !$area) {
            $this->error('Los roles de área requieren --area=<código>.');
            return 1;
        }

        if ($area && !DB::table('areas')->where('code', $area)->where('active', 1)->exists()) {
            $this->error("El área '$area' no existe o está inactiva.");
            return 1;
        }

        $existente = DB::table('users')->where('email', $email)->first();

        if ($existente) {
            DB::table('users')->where('id', $existente->id)->update([
                'display_name' => $this->argument('nombre'),
                'role' => $rol,
                'area' => $area,
                'active' => 1,
            ]);
            $this->info("Usuario existente actualizado (id {$existente->id}).");
            return 0;
        }

        $id = DB::table('users')->insertGetId([
            'email' => $email,
            'display_name' => $this->argument('nombre'),
            'role' => $rol,
            'auth_type' => 'entra',
            'area' => $area,
            'active' => 1,
        ]);

        $this->info("Usuario creado (id $id, rol $rol" . ($area ? ", área $area" : '') . ').');

        return 0;
    }
}
