<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Proveedores;
use App\Models\ProveedorDocumento;
use App\Models\ProveedorHistorial;
use App\Models\ProveedorWorkflowStep;
use App\Models\ProveedorValidacion;
use App\Services\AreaPermissionService;
use App\Services\PlazosService;

class DashboardController extends Controller
{
    protected $permissions;
    protected $plazos;

    public function __construct(AreaPermissionService $permissions, PlazosService $plazos)
    {
        $this->permissions = $permissions;
        $this->plazos = $plazos;
    }

    /**
     * Dashboard principal.
     *
     * GET /v1/dashboard
     */
    public function index(Request $request)
    {
        try {
            $user = $request->auth;

            if (
                !$this->permissions->isInternalUser($user) &&
                !$this->permissions->isProveedor($user)
            ) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $proveedoresQuery = Proveedores::with([
                'workflowSteps',
                'validaciones',
            ]);

            /*
             * Visibilidad centralizada:
             * - admin global ve todo
             * - proveedor ve lo suyo
             * - pm_user ve sus proveedores
             * - usuario_area ve proveedores vinculados a su área
             */
            $this->permissions->applyProveedorVisibilityQuery($proveedoresQuery, $user);

            $proveedores = $proveedoresQuery->get();

            $proveedorIds = $proveedores->pluck('id')->toArray();

            $proveedoresMap = $proveedores->keyBy('id');

            $documentos = empty($proveedorIds)
                ? collect([])
                : ProveedorDocumento::whereIn('proveedor_id', $proveedorIds)->get();

            $workflowSteps = empty($proveedorIds)
                ? collect([])
                : ProveedorWorkflowStep::whereIn('proveedor_id', $proveedorIds)->get();

            $validaciones = empty($proveedorIds)
                ? collect([])
                : ProveedorValidacion::whereIn('proveedor_id', $proveedorIds)->get();

            /*
             * Si el usuario no es admin global, workflow y validaciones deben filtrarse
             * por permisos de área. El proveedor no ve workflow/validaciones internas.
             */
            $workflowVisibles = $workflowSteps->filter(function ($step) use ($user, $proveedoresMap) {
                $proveedor = $proveedoresMap->get($step->proveedor_id);

                if (!$proveedor) {
                    return false;
                }

                if ($this->permissions->isProveedor($user)) {
                    return false;
                }

                return $this->permissions->canViewWorkflowStep($user, $proveedor, $step);
            })->values();

            $validacionesVisibles = $validaciones->filter(function ($validacion) use ($user, $proveedoresMap) {
                $proveedor = $proveedoresMap->get($validacion->proveedor_id);

                if (!$proveedor) {
                    return false;
                }

                if ($this->permissions->isProveedor($user)) {
                    return false;
                }

                return $this->permissions->canViewValidation($user, $proveedor, $validacion);
            })->values();

            $historial = empty($proveedorIds)
                ? collect([])
                : ProveedorHistorial::whereIn('proveedor_id', $proveedorIds)
                    ->orderBy('id', 'desc')
                    ->limit(5)
                    ->get();

            /*
             * KPIs proveedores
             */
            $totalProveedores = $proveedores->count();

            $proveedoresEnRevision = $proveedores->where('estado', 'En revisión')->count();
            $proveedoresObservados = $proveedores->where('estado', 'Observado')->count();
            $proveedoresAprobados = $proveedores->where('estado', 'Aprobado')->count();
            $proveedoresRechazados = $proveedores->where('estado', 'Rechazado')->count();

            $solicitudesActivas = $proveedores
                ->filter(function ($p) {
                    return !in_array($p->estado, ['Aprobado', 'Rechazado']);
                })
                ->count();

            /*
             * KPIs documentos
             */
            $documentosTotal = $documentos->count();

            $documentosPendientes = $documentos->where('estado', 'Pendiente')->count();
            $documentosSubidos = $documentos->where('estado', 'Subido')->count();
            $documentosObservados = $documentos->where('estado', 'Observado')->count();
            $documentosAprobados = $documentos->where('estado', 'Aprobado')->count();

            $documentosPorRevisar = $documentosPendientes + $documentosSubidos;

            $cumplimiento = $documentosTotal > 0
                ? round(($documentosAprobados / $documentosTotal) * 100, 1)
                : 0;

            /*
             * KPIs workflow visible según permisos
             */
            $workflowTotal = $workflowVisibles->count();
            $workflowPendientes = $workflowVisibles->where('status', 'Pendiente')->count();
            $workflowEnRevision = $workflowVisibles->where('status', 'En revisión')->count();
            $workflowObservados = $workflowVisibles->where('status', 'Observado')->count();
            $workflowAprobados = $workflowVisibles->where('status', 'Aprobado')->count();
            $workflowRechazados = $workflowVisibles->where('status', 'Rechazado')->count();

            /*
             * KPIs validaciones visibles según permisos
             */
            $validacionesTotal = $validacionesVisibles->count();
            $validacionesPendientes = $validacionesVisibles->where('status', 'Pendiente')->count();
            $validacionesEnRevision = $validacionesVisibles->where('status', 'En revisión')->count();
            $validacionesObservadas = $validacionesVisibles->where('status', 'Observado')->count();
            $validacionesAprobadas = $validacionesVisibles->where('status', 'Aprobado')->count();
            $validacionesRechazadas = $validacionesVisibles->where('status', 'Rechazado')->count();

            /*
             * Resumen por empresa
             */
            $empresasResumen = $proveedores
                ->groupBy('empresa_id')
                ->map(function ($items, $empresaId) {
                    return [
                        'empresaId' => $empresaId,
                        'cantidad'  => $items->count(),
                    ];
                })
                ->values()
                ->toArray();

            return $this->responseData([
                'data' => [
                    /*
                     * Proveedores
                     */
                    'totalProveedores'      => $totalProveedores,
                    'solicitudesActivas'    => $solicitudesActivas,
                    'proveedoresEnRevision' => $proveedoresEnRevision,
                    'proveedoresObservados' => $proveedoresObservados,
                    'proveedoresAprobados'  => $proveedoresAprobados,
                    'proveedoresRechazados' => $proveedoresRechazados,

                    /*
                     * Documentos
                     */
                    'documentosTotal'       => $documentosTotal,
                    'documentosPendientes'  => $documentosPendientes,
                    'documentosSubidos'     => $documentosSubidos,
                    'documentosPorRevisar'  => $documentosPorRevisar,
                    'documentosObservados'  => $documentosObservados,
                    'documentosAprobados'   => $documentosAprobados,

                    /*
                     * Workflow visible para el usuario
                     */
                    'workflowTotal'         => $workflowTotal,
                    'workflowPendientes'    => $workflowPendientes,
                    'workflowEnRevision'    => $workflowEnRevision,
                    'workflowObservados'    => $workflowObservados,
                    'workflowAprobados'     => $workflowAprobados,
                    'workflowRechazados'    => $workflowRechazados,

                    /*
                     * Validaciones visibles para el usuario
                     */
                    'validacionesTotal'       => $validacionesTotal,
                    'validacionesPendientes'  => $validacionesPendientes,
                    'validacionesEnRevision'  => $validacionesEnRevision,
                    'validacionesObservadas'  => $validacionesObservadas,
                    'validacionesAprobadas'   => $validacionesAprobadas,
                    'validacionesRechazadas'  => $validacionesRechazadas,

                    /*
                     * Cumplimiento
                     */
                    'sla'          => $cumplimiento,
                    'cumplimiento' => $cumplimiento,

                    /*
                     * Contexto
                     */
                    'visibilidad'       => $this->getVisibilidad($user),
                    'actividadReciente' => $this->mapActividad($historial, $proveedoresMap),
                    'empresasResumen'   => $empresasResumen,

                    /*
                     * Tiempos por etapa y etapas fuera de plazo (solo internos).
                     */
                    'plazos' => $this->permissions->isProveedor($user)
                        ? null
                        : $this->plazos->resumen($proveedores),
                ]
            ], 200);

        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actividad reciente.
     *
     * GET /v1/dashboard/actividad-reciente
     */
    public function actividadReciente(Request $request)
    {
        try {
            $user = $request->auth;

            if (
                !$this->permissions->isInternalUser($user) &&
                !$this->permissions->isProveedor($user)
            ) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $query = Proveedores::query();

            $this->permissions->applyProveedorVisibilityQuery($query, $user);

            $proveedores = $query->get();

            $proveedorIds = $proveedores->pluck('id')->toArray();

            $proveedoresMap = $proveedores->keyBy('id');

            $historial = empty($proveedorIds)
                ? collect([])
                : ProveedorHistorial::whereIn('proveedor_id', $proveedorIds)
                    ->orderBy('id', 'desc')
                    ->limit(10)
                    ->get();

            return $this->responseData([
                'data' => $this->mapActividad($historial, $proveedoresMap),
            ], 200);

        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function mapActividad($historial, $proveedoresMap = null): array
    {
        if (!$historial) {
            return [];
        }

        return $historial->map(function ($item) use ($proveedoresMap) {
            $proveedor = $proveedoresMap
                ? $proveedoresMap->get($item->proveedor_id)
                : null;

            return [
                'id'          => (string) $item->id,
                'accion'      => $item->accion,
                'fecha'       => $item->created_at,
                'createdAt'   => $item->created_at,
                'realizadoPor'=> $item->realizado_por,
                'proveedorId' => $item->proveedor_id
                    ? (string) $item->proveedor_id
                    : null,
                'razonSocial' => $proveedor
                    ? $proveedor->razon_social
                    : null,
            ];
        })->values()->toArray();
    }

    /**
     * Visibilidad basada en permisos reales.
     */
    private function getVisibilidad($user): string
    {
        if ($this->permissions->isAdminGlobal($user)) {
            return 'Global';
        }

        if ($this->permissions->isAuditor($user)) {
            return 'Auditoría (solo lectura)';
        }

        if ($this->permissions->isProveedor($user)) {
            return 'Mi cuenta';
        }

        if ($this->permissions->isLegacyPmUser($user)) {
            return 'Mis proveedores';
        }

        if ($this->permissions->isAreaUser($user)) {
            return 'Área: ' . strtoupper($user->area);
        }

        return 'Restringido';
    }
}