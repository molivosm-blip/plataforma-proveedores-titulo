<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Proveedores;
use App\Models\ProveedorValidacion;
use App\Models\ProveedorWorkflowStep;
use App\Models\ProveedorHistorial;
use App\Services\AreaPermissionService;
use App\Services\NotificacionService;

class ValidacionesController extends Controller
{
    protected $permissions;
    protected $notificaciones;

    public function __construct(AreaPermissionService $permissions, NotificacionService $notificaciones)
    {
        $this->permissions = $permissions;
        $this->notificaciones = $notificaciones;
    }

    /**
     * Ver validaciones de un proveedor.
     *
     * GET /v1/validaciones/proveedores/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $proveedor = Proveedores::with([
                'validaciones',
            ])->findOrFail($id);

            if (!$this->permissions->canViewProveedor($user, $proveedor)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $validaciones = $proveedor->validaciones
                ->sortBy(function ($v) {
                    return $this->areaOrder($v->area);
                })
                ->filter(function ($v) use ($user, $proveedor) {
                    return $this->permissions->canViewValidation($user, $proveedor, $v);
                })
                ->values()
                ->map(function ($v) {
                    return $this->mapValidacion($v);
                });

            return $this->responseData([
                'data' => [
                    'proveedor' => [
                        'id'          => (string) $proveedor->id,
                        'razonSocial' => $proveedor->razon_social,
                        'rut'         => $proveedor->rut,
                        'estado'      => $proveedor->estado,
                        'empresaId'   => $proveedor->empresa_id,
                    ],
                    'validaciones' => $validaciones,
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->respuestaValidacion($e, isset($transactionStarted) && $transactionStarted);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar validaciones pendientes del usuario autenticado.
     *
     * GET /v1/validaciones/mis-pendientes
     */
    public function misPendientes(Request $request)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $query = ProveedorValidacion::with('proveedor')
                ->whereIn('status', ['Pendiente', 'En revisión', 'Observado'])
                ->orderBy('id', 'desc');

            if (!$this->permissions->isAdminGlobal($user)) {
                if ($this->permissions->isLegacyPmUser($user)) {
                    $query->where('area', 'pm')
                        ->whereHas('proveedor', function ($q) use ($user) {
                            $q->where('created_by_user_id', $user->id);
                        });
                } else {
                    if (empty($user->area)) {
                        $query->whereRaw('1 = 0');
                    } else {
                        $query->where('area', $user->area);
                    }
                }
            }

            $items = $query->get()
                ->filter(function ($validacion) use ($user) {
                    if (!$validacion->proveedor) {
                        return false;
                    }

                    return $this->permissions->canViewValidation(
                        $user,
                        $validacion->proveedor,
                        $validacion
                    );
                })
                ->map(function ($validacion) {
                    return [
                        'validacion' => $this->mapValidacion($validacion),
                        'proveedor' => $validacion->proveedor ? [
                            'id'          => (string) $validacion->proveedor->id,
                            'razonSocial' => $validacion->proveedor->razon_social,
                            'rut'         => $validacion->proveedor->rut,
                            'estado'      => $validacion->proveedor->estado,
                            'empresaId'   => $validacion->proveedor->empresa_id,
                        ] : null,
                    ];
                })
                ->values();

            return $this->responseData([
                'data' => $items,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->respuestaValidacion($e, isset($transactionStarted) && $transactionStarted);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar validación por área.
     *
     * PUT /v1/validaciones/proveedores/{id}/areas/{area}
     */
    public function updateArea(Request $request, $id, $area)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $this->validate($request, [
                'status'   => 'required|in:Pendiente,En revisión,Observado,Aprobado,Rechazado',
                'comments' => 'nullable|string',
            ]);

            $proveedor = Proveedores::with([
                'validaciones',
                'workflowSteps',
            ])->findOrFail($id);

            $validacion = ProveedorValidacion::where('proveedor_id', $proveedor->id)
                ->where('area', $area)
                ->firstOrFail();

            if (!$this->permissions->canUpdateValidation($user, $proveedor, $validacion)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Sin permisos'
                ], 403);
            }

            if ($validacion->status === 'Aprobado' && !$this->permissions->isAdminGlobal($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'La validación ya fue aprobada'
                ], 422);
            }

            DB::beginTransaction();
            $transactionStarted = true;

            $statusAnterior = $validacion->status;
            $nuevoStatus = $request->input('status');

            $validacion->status = $nuevoStatus;
            $validacion->comments = $request->input('comments');
            $validacion->updated_at = date('Y-m-d H:i:s');

            if (in_array($nuevoStatus, ['Aprobado', 'Rechazado', 'Observado'])) {
                $validacion->validated_by_user_id = $user->id;
                $validacion->validated_at = date('Y-m-d H:i:s');
            }

            if (in_array($nuevoStatus, ['Pendiente', 'En revisión'])) {
                $validacion->validated_by_user_id = null;
                $validacion->validated_at = null;
            }

            $validacion->save();

            $this->syncWorkflowStep(
                $proveedor->id,
                $area,
                $nuevoStatus,
                $request->input('comments')
            );

            ProveedorHistorial::create([
                'proveedor_id'  => $proveedor->id,
                'accion'        => 'Validación ' . $validacion->area_name . ' cambió de ' . $statusAnterior . ' a ' . $nuevoStatus,
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $this->syncProveedorEstado($proveedor);

            DB::commit();
            $transactionStarted = false;

            /*
             * Un error al notificar no debe revertir la validación ya guardada.
             */
            if ($statusAnterior !== $nuevoStatus) {
                try {
                    $this->notificarCambio($proveedor, $validacion, $request->input('comments'));
                } catch (\Throwable $e) {
                    error_log('No se pudo notificar el cambio de validación: ' . $e->getMessage());
                }
            }

            return $this->responseData([
                'message' => 'Validación actualizada correctamente',
                'data'    => $this->mapValidacion($validacion),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->respuestaValidacion($e, isset($transactionStarted) && $transactionStarted);
        } catch (\Exception $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reabrir validación.
     *
     * Solo admin global puede reabrir.
     *
     * PUT /v1/validaciones/proveedores/{id}/areas/{area}/reabrir
     */
    public function reabrirArea(Request $request, $id, $area)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->canReopenValidation($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $proveedor = Proveedores::findOrFail($id);

            $validacion = ProveedorValidacion::where('proveedor_id', $proveedor->id)
                ->where('area', $area)
                ->firstOrFail();

            DB::beginTransaction();
            $transactionStarted = true;

            $statusAnterior = $validacion->status;

            $validacion->status = 'En revisión';
            $validacion->comments = $request->input('comments', $validacion->comments);
            $validacion->validated_by_user_id = null;
            $validacion->validated_at = null;
            $validacion->updated_at = date('Y-m-d H:i:s');
            $validacion->save();

            $this->syncWorkflowStep(
                $proveedor->id,
                $area,
                'En revisión',
                $validacion->comments
            );

            ProveedorHistorial::create([
                'proveedor_id'  => $proveedor->id,
                'accion'        => 'Validación ' . $validacion->area_name . ' reabierta desde ' . $statusAnterior . ' a En revisión',
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $this->syncProveedorEstado($proveedor);

            DB::commit();

            return $this->responseData([
                'message' => 'Validación reabierta',
                'data'    => $this->mapValidacion($validacion),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->respuestaValidacion($e, isset($transactionStarted) && $transactionStarted);
        } catch (\Exception $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

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

    /**
     * Avisos por cambio de estado de una validación:
     * - Aprobado: se avisa a la siguiente área pendiente del flujo.
     * - Observado o Rechazado: se avisa al solicitante.
     * - Proveedor aprobado por todas las áreas: se avisa al solicitante,
     *   al proveedor y a Datos Maestros.
     */
    private function notificarCambio(Proveedores $proveedor, ProveedorValidacion $validacion, ?string $comentario): void
    {
        $proveedor->refresh();
        $referencia = 'validacion:' . $validacion->id . ':' . $validacion->status;
        $detalle = $comentario ? ' Comentario: ' . $comentario : '';

        if (in_array($validacion->status, ['Observado', 'Rechazado'], true)) {
            $this->notificaciones->notificarSolicitante(
                $proveedor,
                'validacion_' . mb_strtolower($validacion->status),
                $validacion->area_name . ': ' . $validacion->status,
                $validacion->area_name . ' dejó a ' . $proveedor->razon_social . ' en estado ' . $validacion->status . '.' . $detalle,
                $referencia
            );

            return;
        }

        if ($validacion->status !== 'Aprobado') {
            return;
        }

        if ($proveedor->estado === 'Aprobado') {
            $mensaje = $proveedor->razon_social . ' fue aprobado por todas las áreas.';

            $this->notificaciones->notificarSolicitante($proveedor, 'proveedor_aprobado', 'Proveedor aprobado', $mensaje, $referencia);
            $this->notificaciones->notificarProveedor($proveedor, 'proveedor_aprobado', 'Tu incorporación fue aprobada', $mensaje, $referencia);
            $this->notificaciones->notificarArea(
                'datos_maestros',
                $proveedor,
                'proveedor_aprobado',
                'Proveedor listo para SAP',
                $mensaje . ' Puedes enviarlo al servicio de SAP.',
                $referencia
            );

            return;
        }

        $siguiente = ProveedorValidacion::where('proveedor_id', $proveedor->id)
            ->whereIn('status', ['Pendiente', 'En revisión'])
            ->get()
            ->sortBy(function ($v) {
                return $this->areaOrder($v->area);
            })
            ->first(function ($v) use ($validacion) {
                return $this->areaOrder($v->area) > $this->areaOrder($validacion->area);
            });

        if ($siguiente) {
            $this->notificaciones->notificarArea(
                $siguiente->area,
                $proveedor,
                'etapa_disponible',
                'Proveedor listo para tu revisión',
                $validacion->area_name . ' aprobó a ' . $proveedor->razon_social . '. Ahora corresponde la revisión de ' . $siguiente->area_name . '.',
                $referencia
            );
        }
    }

    private function syncWorkflowStep($proveedorId, $area, $status, ?string $comments = null): void
    {
        $workflowStep = ProveedorWorkflowStep::where('proveedor_id', $proveedorId)
            ->where('area', $area)
            ->first();

        if (!$workflowStep) {
            return;
        }

        $workflowStep->status = $status;
        $workflowStep->comments = $comments;
        $workflowStep->updated_at = date('Y-m-d H:i:s');

        if (in_array($status, ['Aprobado', 'Rechazado', 'Observado'])) {
            $workflowStep->validated_at = date('Y-m-d H:i:s');
        }

        if (in_array($status, ['Pendiente', 'En revisión'])) {
            $workflowStep->validated_by_user_id = null;
            $workflowStep->validated_at = null;
        }

        $workflowStep->save();
    }

    /**
     * El estado general lo decide una sola regla (ProveedorEstadoService):
     * este controlador no calcula uno propio.
     */
    private function syncProveedorEstado(Proveedores $proveedor): void
    {
        $estado = app(\App\Services\ProveedorEstadoService::class)
            ->recalcularEstadoProveedor($proveedor->id);

        $proveedor->estado = $estado;
        $proveedor->syncOriginalAttribute('estado');
    }

    private function areaOrder($area): int
    {
        $orden = [
            'pm'               => 1,
            'contraloria'      => 2,
            'legal_crediticia' => 3,
            'legal_judicial'   => 4,
            'datos_maestros'   => 5,
        ];

        return isset($orden[$area]) ? $orden[$area] : 99;
    }

    private function mapValidacion($v): array
    {
        return [
            'id'                => (string) $v->id,
            'proveedorId'       => $v->proveedor_id ? (string) $v->proveedor_id : null,
            'area'              => $v->area,
            'areaName'          => $v->area_name,
            'status'            => $v->status,
            'validatedByUserId' => $v->validated_by_user_id ? (string) $v->validated_by_user_id : null,
            'validatedAt'       => $v->validated_at,
            'comments'          => $v->comments,
            'createdAt'         => $v->created_at,
            'updatedAt'         => $v->updated_at,
        ];
    }
}