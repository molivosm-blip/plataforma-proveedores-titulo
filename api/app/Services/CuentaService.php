<?php

namespace App\Services;

use App\Models\Proveedores;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Cuenta del usuario: perfil, último ingreso y preferencias de correo
|--------------------------------------------------------------------------
|
| Las notificaciones siempre quedan en la campana del portal. Lo que el
| usuario puede elegir es si además le llegan por correo, por grupo de aviso.
| Si no ha elegido nada, se envían todos (comportamiento anterior).
|
| Los avisos de seguridad (por ejemplo, el cambio de contraseña) y cualquier
| tipo que no pertenezca a un grupo se envían siempre.
|
| Requiere users.ultimo_ingreso_at, users.ingreso_anterior_at y la tabla
| usuario_preferencias_notificacion (php artisan cuenta:aplicar las crea).
| Sin ellas el portal sigue funcionando: no guarda ingresos y envía todo.
|
*/
class CuentaService
{
    const TABLA_PREFERENCIAS = 'usuario_preferencias_notificacion';

    const ROLES_INTERNOS = ['pm_admin', 'pm_user', 'admin_portal', 'admin_area', 'usuario_area'];

    /**
     * Grupos de avisos. 'tipos' admite prefijos terminados en * .
     */
    const GRUPOS = [
        'estado' => [
            'titulo'      => 'Cambios en el proceso',
            'descripcion' => 'Aprobaciones, observaciones, rechazos y nuevas etapas disponibles.',
            'tipos'       => ['proveedor_aprobado', 'validacion_*', 'etapa_disponible', 'sap_*', 'datos_maestros'],
            'roles'       => null,
        ],
        'documentos' => [
            'titulo'      => 'Documentos y declaraciones',
            'descripcion' => 'Documentos subidos u observados y declaraciones firmadas.',
            'tipos'       => ['documento_subido', 'documento_observado', 'declaracion_firmada'],
            'roles'       => null,
        ],
        'plazos' => [
            'titulo'      => 'Plazos y reevaluaciones',
            'descripcion' => 'Etapas con plazo vencido y reevaluaciones periódicas de proveedores.',
            'tipos'       => ['plazo_vencido', 'reevaluacion_alerta'],
            'roles'       => self::ROLES_INTERNOS,
        ],
        'alertas' => [
            'titulo'      => 'Alertas de riesgo',
            'descripcion' => 'Declaraciones de personas expuestas políticamente (PEP).',
            'tipos'       => ['alerta_pep'],
            'roles'       => self::ROLES_INTERNOS,
        ],
    ];

    /** Grupo al que pertenece un tipo de aviso, o null si no pertenece a ninguno. */
    public function grupoDeTipo(string $tipo): ?string
    {
        foreach (self::GRUPOS as $clave => $grupo) {
            foreach ($grupo['tipos'] as $patron) {
                if (substr($patron, -1) === '*') {
                    if (strpos($tipo, substr($patron, 0, -1)) === 0) {
                        return $clave;
                    }
                } elseif ($patron === $tipo) {
                    return $clave;
                }
            }
        }

        return null;
    }

    public function preferenciasDisponibles(): bool
    {
        return Schema::hasTable(self::TABLA_PREFERENCIAS);
    }

    /** ¿Debe enviarse por correo un aviso de este tipo a este usuario? */
    public function correoActivado(int $userId, string $tipo): bool
    {
        $grupo = $this->grupoDeTipo($tipo);

        if ($grupo === null || !$this->preferenciasDisponibles()) {
            return true;
        }

        $valor = DB::table(self::TABLA_PREFERENCIAS)
            ->where('user_id', $userId)
            ->where('grupo', $grupo)
            ->value('correo');

        return $valor === null ? true : (int) $valor === 1;
    }

    /** Grupos que aplican al rol del usuario, con su valor actual. */
    public function preferencias(User $user): array
    {
        $guardadas = [];

        if ($this->preferenciasDisponibles()) {
            $guardadas = DB::table(self::TABLA_PREFERENCIAS)
                ->where('user_id', $user->id)
                ->pluck('correo', 'grupo')
                ->all();
        }

        $salida = [];

        foreach (self::GRUPOS as $clave => $grupo) {
            if ($grupo['roles'] !== null && !in_array($user->role, $grupo['roles'], true)) {
                continue;
            }

            $salida[] = [
                'grupo'       => $clave,
                'titulo'      => $grupo['titulo'],
                'descripcion' => $grupo['descripcion'],
                'correo'      => array_key_exists($clave, $guardadas) ? (int) $guardadas[$clave] === 1 : true,
            ];
        }

        return $salida;
    }

    /**
     * Guarda las preferencias. $valores: ['estado' => true, 'documentos' => false, ...].
     * Ignora los grupos que no aplican al rol del usuario.
     *
     * @throws \InvalidArgumentException si falta la tabla o un valor no es booleano.
     */
    public function guardarPreferencias(User $user, array $valores): array
    {
        if (!$this->preferenciasDisponibles()) {
            throw new \InvalidArgumentException('Las preferencias no están disponibles: falta ejecutar php artisan cuenta:aplicar.');
        }

        $aplicables = array_column($this->preferencias($user), 'grupo');
        $ahora = date('Y-m-d H:i:s');

        foreach ($valores as $grupo => $correo) {
            if (!in_array($grupo, $aplicables, true)) {
                continue;
            }

            if (!is_bool($correo)) {
                throw new \InvalidArgumentException('El valor de "' . $grupo . '" debe ser verdadero o falso.');
            }

            $existe = DB::table(self::TABLA_PREFERENCIAS)
                ->where('user_id', $user->id)->where('grupo', $grupo)->exists();

            if ($existe) {
                DB::table(self::TABLA_PREFERENCIAS)
                    ->where('user_id', $user->id)->where('grupo', $grupo)
                    ->update(['correo' => $correo ? 1 : 0, 'updated_at' => $ahora]);
            } else {
                DB::table(self::TABLA_PREFERENCIAS)->insert([
                    'user_id' => $user->id, 'grupo' => $grupo,
                    'correo' => $correo ? 1 : 0, 'updated_at' => $ahora,
                ]);
            }
        }

        return $this->preferencias($user);
    }

    /** Guarda el ingreso actual y conserva el anterior. */
    public function registrarIngreso(User $user): void
    {
        if (!Schema::hasColumn('users', 'ultimo_ingreso_at') || !Schema::hasColumn('users', 'ingreso_anterior_at')) {
            return;
        }

        $fila = DB::table('users')->where('id', $user->id)->first(['ultimo_ingreso_at']);

        DB::table('users')->where('id', $user->id)->update([
            'ingreso_anterior_at' => $fila ? $fila->ultimo_ingreso_at : null,
            'ultimo_ingreso_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** Datos que muestra la página "Mi cuenta". */
    public function perfil(User $user): array
    {
        $fila = null;

        if (Schema::hasColumn('users', 'ultimo_ingreso_at') && Schema::hasColumn('users', 'ingreso_anterior_at')) {
            $fila = DB::table('users')->where('id', $user->id)->first(['ultimo_ingreso_at', 'ingreso_anterior_at']);
        }

        $perfil = [
            'id'              => (string) $user->id,
            'email'           => $user->email,
            'displayName'     => $user->display_name,
            'role'            => $user->role,
            'area'            => $user->area,
            'authType'        => $user->role === 'proveedor' ? 'local' : 'entra',
            'puedeCambiarPassword' => $user->role === 'proveedor',
            'creadoEn'        => $user->created_at,
            'ingresoActual'   => $fila ? $fila->ultimo_ingreso_at : null,
            'ingresoAnterior' => $fila ? $fila->ingreso_anterior_at : null,
            'proveedor'       => null,
        ];

        if ($user->role === 'proveedor') {
            $proveedor = Proveedores::where('proveedor_user_id', $user->id)->first();

            if ($proveedor) {
                $perfil['proveedor'] = [
                    'id'          => (string) $proveedor->id,
                    'razonSocial' => $proveedor->razon_social,
                    'rut'         => $proveedor->rut,
                    'tipo'        => $proveedor->tipo,
                    'giro'        => $proveedor->giro,
                    'estado'      => $proveedor->estado,
                ];
            }
        }

        return $perfil;
    }
}
