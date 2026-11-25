<?php

namespace App\Http\Controllers;

use App\Models\ProveedorSapEnvio;
use App\Models\Proveedores;
use App\Services\AreaPermissionService;
use App\Services\NotificacionService;
use App\Services\SapMockService;
use Illuminate\Http\Request;

class SapMockController extends Controller
{
    private const AREA = 'datos_maestros';

    protected $permissions;
    protected $sap;
    protected $notificaciones;

    public function __construct(
        AreaPermissionService $permissions,
        SapMockService $sap,
        NotificacionService $notificaciones
    ) {
        $this->permissions = $permissions;
        $this->sap = $sap;
        $this->notificaciones = $notificaciones;
    }

    /**
     * Mensaje que se enviaría a SAP, motivo de bloqueo y envíos anteriores.
     *
     * GET /v1/interno/proveedores/{id}/sap
     */
    public function show(Request $request, $id)
    {
        try {
            $proveedor = Proveedores::with('detalle')->findOrFail($id);

            if (!$this->permissions->actsForArea($request->auth, self::AREA)) {
                return $this->responseData(['error' => true, 'message' => 'Solo Datos Maestros puede enviar proveedores a SAP'], 403);
            }

            $envios = ProveedorSapEnvio::where('proveedor_id', $proveedor->id)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($e) {
                    return $this->map($e);
                })
                ->values();

            return $this->responseData([
                'data' => [
                    'payload'      => $this->sap->construirPayload($proveedor),
                    'motivoBloqueo' => $this->sap->motivoBloqueo($proveedor),
                    'envios'       => $envios,
                ],
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /v1/interno/proveedores/{id}/sap/enviar
     */
    public function enviar(Request $request, $id)
    {
        try {
            $user = $request->auth;
            $proveedor = Proveedores::with('detalle')->findOrFail($id);

            if (!$this->permissions->actsForArea($user, self::AREA)) {
                return $this->responseData(['error' => true, 'message' => 'Solo Datos Maestros puede enviar proveedores a SAP'], 403);
            }

            $motivo = $this->sap->motivoBloqueo($proveedor);

            if ($motivo) {
                return $this->responseData(['error' => true, 'message' => $motivo], 422);
            }

            $envio = $this->sap->enviar($proveedor, $user);

            if ($envio->estado === 'exitoso') {
                $this->notificaciones->notificarSolicitante(
                    $proveedor,
                    'sap_creado',
                    'Proveedor creado en SAP (simulado)',
                    $proveedor->razon_social . ' quedó registrado con el número ' . $envio->numero_proveedor_sap . '.',
                    'sap:' . $envio->id
                );
            }

            return $this->responseData([
                'message' => 'Envío registrado',
                'data'    => $this->map($envio),
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    private function map(ProveedorSapEnvio $e): array
    {
        $respuesta = json_decode((string) $e->respuesta_json, true);

        return [
            'id'                 => (string) $e->id,
            'estado'             => $e->estado,
            'numeroProveedorSap' => $e->numero_proveedor_sap,
            'respuesta'          => is_array($respuesta) ? $respuesta : null,
            'createdAt'          => $e->created_at,
        ];
    }
}
