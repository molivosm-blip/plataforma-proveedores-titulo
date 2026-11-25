<?php

namespace App\Http\Controllers;

use App\Models\ProveedorDeclaracion;
use App\Models\Proveedores;
use App\Services\AreaPermissionService;
use App\Services\DeclaracionService;
use Illuminate\Http\Request;

class DeclaracionesController extends Controller
{
    protected $permissions;
    protected $declaraciones;

    public function __construct(AreaPermissionService $permissions, DeclaracionService $declaraciones)
    {
        $this->permissions = $permissions;
        $this->declaraciones = $declaraciones;
    }

    /**
     * Declaraciones firmadas de un proveedor.
     *
     * GET /v1/interno/proveedores/{id}/declaraciones
     * GET /v1/proveedor/solicitudes/{id}/declaraciones
     */
    public function index(Request $request, $id)
    {
        try {
            $user = $request->auth;
            $proveedor = Proveedores::findOrFail($id);

            if (!$this->permissions->canViewProveedor($user, $proveedor)) {
                return $this->responseData(['error' => true, 'message' => 'No autorizado'], 403);
            }

            $items = ProveedorDeclaracion::where('proveedor_id', $proveedor->id)
                ->orderBy('tipo')
                ->get()
                ->filter(function ($d) use ($user) {
                    /*
                     * El proveedor no ve la declaración interna del solicitante.
                     */
                    return !$this->permissions->isProveedor($user)
                        || $d->tipo === ProveedorDeclaracion::TIPO_PEP;
                })
                ->map(function ($d) {
                    return $this->map($d);
                })
                ->values();

            return $this->responseData(['data' => $items], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * El solicitante firma la declaración de conflicto de interés.
     *
     * POST /v1/interno/proveedores/{id}/declaraciones/conflicto-interes
     */
    public function firmarConflicto(Request $request, $id)
    {
        try {
            $user = $request->auth;
            $proveedor = Proveedores::findOrFail($id);

            $esSolicitante = (int) $proveedor->created_by_user_id === (int) $user->id;

            if (!$esSolicitante && !$this->permissions->isAdminGlobal($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Solo el solicitante del proveedor puede firmar esta declaración',
                ], 403);
            }

            return $this->firmar($request, $proveedor, ProveedorDeclaracion::TIPO_CONFLICTO);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * El proveedor firma la declaración de relacionados y PEP.
     *
     * POST /v1/proveedor/solicitudes/{id}/declaraciones/relacionados-pep
     */
    public function firmarPep(Request $request, $id)
    {
        try {
            $user = $request->auth;
            $proveedor = Proveedores::findOrFail($id);

            if ((int) $proveedor->proveedor_user_id !== (int) $user->id) {
                return $this->responseData(['error' => true, 'message' => 'No autorizado'], 403);
            }

            return $this->firmar($request, $proveedor, ProveedorDeclaracion::TIPO_PEP);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    private function firmar(Request $request, Proveedores $proveedor, string $tipo)
    {
        try {
            $respuestas = $this->declaraciones->normalizarRespuestas($tipo, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 422);
        }

        try {
            $declaracion = $this->declaraciones->firmar(
                $proveedor,
                $tipo,
                $respuestas,
                $request->auth,
                $request->ip(),
                $request->userAgent()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 422);
        }

        return $this->responseData([
            'message' => 'Declaración firmada correctamente',
            'data'    => $this->map($declaracion),
        ], 200);
    }

    private function map(ProveedorDeclaracion $d): array
    {
        return [
            'id'                  => (string) $d->id,
            'proveedorId'         => (string) $d->proveedor_id,
            'documentoId'         => $d->documento_id ? (string) $d->documento_id : null,
            'tipo'                => $d->tipo,
            'respuestas'          => $d->respuestas(),
            'declaraRelacionados' => (bool) $d->declara_relacionados,
            'declaraPep'          => (bool) $d->declara_pep,
            'declaranteNombre'    => $d->declarante_nombre,
            'declaranteRut'       => $d->declarante_rut,
            'declaranteCargo'     => $d->declarante_cargo,
            'declaranteEmail'     => $d->declarante_email,
            'firmadoAt'           => $d->firmado_at,
            'firmaHash'           => $d->firma_hash,
            'integridadVerificada' => $this->declaraciones->verificarIntegridad($d),
        ];
    }
}
