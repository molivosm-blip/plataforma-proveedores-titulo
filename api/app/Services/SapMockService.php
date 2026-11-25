<?php

namespace App\Services;

use App\Models\ProveedorHistorial;
use App\Models\ProveedorSapEnvio;
use App\Models\Proveedores;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Servicio simulado de integración con SAP
|--------------------------------------------------------------------------
|
| No se conecta con SAP. Reproduce el intercambio diseñado para una futura
| integración: arma el mensaje con los datos del proveedor aprobado,
| valida los campos obligatorios y devuelve una respuesta de éxito,
| observación o error, que queda registrada en proveedor_sap_envios.
|
| Punto de activación: proveedor en estado Aprobado, con todas las áreas
| aprobadas. Lo ejecuta Datos Maestros.
|
| Los valores fijos son los que el procedimiento de creación de
| proveedores indica para la carga manual en SAP.
|
| Para probar el manejo de errores técnicos se puede definir
| SAP_MOCK_FORZAR_ERROR=true en el .env.
|
*/
class SapMockService
{
    private const VALORES_FIJOS = [
        'cuenta_asociada'     => '2102011001',
        'grupo_tesoreria'     => 'Z005',
        'via_pago'            => 'V',
        'moneda_pedido'       => 'CLP',
        'condicion_expedicion' => '01',
        'grupo_esquema'       => 'Z1',
    ];

    /*
     * Campos que deben venir con datos reales. El procedimiento actual
     * permite cargar valores genéricos (SIN CONTACTO, cuenta 1111);
     * el servicio los rechaza para mejorar la calidad del dato.
     */
    private const OBLIGATORIOS = [
        'razon_social'           => 'Razón social',
        'rut'                    => 'RUT',
        'giro'                   => 'Giro',
        'direccion'              => 'Dirección',
        'comuna'                 => 'Comuna',
        'banco_pago'             => 'Banco para pago',
        'cuenta_corriente'       => 'Cuenta corriente',
        'persona_contacto'       => 'Persona de contacto',
        'persona_contacto_email' => 'Correo de contacto',
        'plazo_pago'             => 'Plazo de pago',
    ];

    private const GENERICOS = ['SIN CONTACTO', 'SINCORREO@HOLDING.COM', '1111', 'PENDIENTE'];

    public function construirPayload(Proveedores $proveedor): array
    {
        $d = $proveedor->detalle;

        $sociedades = DB::table('proveedor_sociedades_habilitadas')
            ->where('proveedor_id', $proveedor->id)
            ->orderBy('sociedad_nombre')
            ->pluck('sociedad_nombre')
            ->toArray();

        return [
            'operacion'  => 'CREAR_PROVEEDOR',
            'origen'     => 'PORTAL_PROVEEDORES',
            'referencia' => 'PORTAL-' . $proveedor->id,
            'datos_generales' => [
                'razon_social'  => $proveedor->razon_social,
                'rut'           => $proveedor->rut,
                'giro'          => $proveedor->giro,
                'direccion'     => $d ? $d->direccion : null,
                'comuna'        => $d ? $d->comuna : null,
                'region'        => $d ? $d->region : null,
                'codigo_postal' => $d ? $d->codigo_postal : null,
                'pais'          => $d && $d->pais ? $d->pais : 'Chile',
                'telefono'      => $d ? $d->telefono : null,
                'tipo'          => $proveedor->tipo,
            ],
            'contacto' => [
                'persona_contacto'          => $d ? $d->persona_contacto : null,
                'persona_contacto_cargo'    => $d ? $d->persona_contacto_cargo : null,
                'persona_contacto_telefono' => $d ? $d->persona_contacto_telefono : null,
                'persona_contacto_email'    => $d ? $d->persona_contacto_email : null,
            ],
            'datos_bancarios' => [
                'banco_pago'       => $d ? $d->banco_pago : null,
                'cuenta_corriente' => $d ? $d->cuenta_corriente : null,
                'forma_pago'       => $d ? $d->forma_pago : null,
            ],
            'sociedad' => [
                'sociedades'      => $sociedades,
                'cuenta_asociada' => self::VALORES_FIJOS['cuenta_asociada'],
                'grupo_tesoreria' => self::VALORES_FIJOS['grupo_tesoreria'],
                'via_pago'        => self::VALORES_FIJOS['via_pago'],
            ],
            'compras' => [
                'moneda_pedido'        => $d && $d->moneda ? $d->moneda : self::VALORES_FIJOS['moneda_pedido'],
                'plazo_pago'           => $d ? $d->plazo_pago : null,
                'condicion_expedicion' => self::VALORES_FIJOS['condicion_expedicion'],
                'grupo_esquema'        => self::VALORES_FIJOS['grupo_esquema'],
            ],
        ];
    }

    /**
     * Verifica el punto de activación. Devuelve null si se puede enviar
     * o el motivo por el que no.
     */
    public function motivoBloqueo(Proveedores $proveedor): ?string
    {
        if ($proveedor->estado !== 'Aprobado') {
            return 'El proveedor debe estar Aprobado por todas las áreas antes del envío a SAP.';
        }

        $pendientes = DB::table('proveedor_validaciones')
            ->where('proveedor_id', $proveedor->id)
            ->where('status', '<>', 'Aprobado')
            ->count();

        if ($pendientes > 0) {
            return 'Hay validaciones de área sin aprobar.';
        }

        return null;
    }

    public function enviar(Proveedores $proveedor, User $user): ProveedorSapEnvio
    {
        $payload = $this->construirPayload($proveedor);
        $respuesta = $this->simularRespuesta($proveedor, $payload);

        $envio = ProveedorSapEnvio::create([
            'proveedor_id'         => $proveedor->id,
            'estado'               => $respuesta['estado'],
            'payload_json'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'respuesta_json'       => json_encode($respuesta, JSON_UNESCAPED_UNICODE),
            'numero_proveedor_sap' => $respuesta['numero_proveedor'] ?? null,
            'created_by_user_id'   => $user->id,
            'created_at'           => date('Y-m-d H:i:s'),
        ]);

        ProveedorHistorial::create([
            'proveedor_id'  => $proveedor->id,
            'accion'        => 'Envío a SAP (simulado): ' . $respuesta['estado'] . ' — ' . $respuesta['mensaje'],
            'realizado_por' => $user->display_name ?: $user->email,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return $envio;
    }

    private function simularRespuesta(Proveedores $proveedor, array $payload): array
    {
        $fecha = date('Y-m-d H:i:s');

        if (filter_var(env('SAP_MOCK_FORZAR_ERROR', false), FILTER_VALIDATE_BOOLEAN)) {
            return [
                'estado'  => 'error',
                'codigo'  => 'E-TIMEOUT',
                'mensaje' => 'Tiempo de espera agotado al comunicarse con SAP (error simulado). Reintentar más tarde.',
                'fecha'   => $fecha,
            ];
        }

        $previo = ProveedorSapEnvio::where('proveedor_id', $proveedor->id)
            ->where('estado', 'exitoso')
            ->first();

        if ($previo) {
            return [
                'estado'           => 'error',
                'codigo'           => 'E-DUPLICADO',
                'mensaje'          => 'El proveedor ya existe en SAP con el número ' . $previo->numero_proveedor_sap . '.',
                'numero_proveedor' => null,
                'fecha'            => $fecha,
            ];
        }

        $faltantes = $this->camposFaltantes($payload);

        if (count($faltantes) > 0) {
            return [
                'estado'    => 'observado',
                'codigo'    => 'W-DATOS',
                'mensaje'   => 'Faltan datos obligatorios o tienen valores genéricos: ' . implode(', ', $faltantes) . '.',
                'faltantes' => $faltantes,
                'fecha'     => $fecha,
            ];
        }

        return [
            'estado'           => 'exitoso',
            'codigo'           => 'S-CREADO',
            'mensaje'          => 'Proveedor creado en SAP (simulado).',
            'numero_proveedor' => 'MOCK' . str_pad((string) (100000 + $proveedor->id), 6, '0', STR_PAD_LEFT),
            'fecha'            => $fecha,
        ];
    }

    private function camposFaltantes(array $payload): array
    {
        $planos = array_merge(
            $payload['datos_generales'],
            $payload['contacto'],
            $payload['datos_bancarios'],
            $payload['compras']
        );

        $faltantes = [];

        foreach (self::OBLIGATORIOS as $campo => $label) {
            $valor = mb_strtoupper(trim((string) ($planos[$campo] ?? '')));

            if ($valor === '' || in_array($valor, self::GENERICOS, true)) {
                $faltantes[] = $label;
            }
        }

        if (count($payload['sociedad']['sociedades']) === 0) {
            $faltantes[] = 'Sociedades habilitadas';
        }

        return $faltantes;
    }
}
