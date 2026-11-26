<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Proveedores;
use App\Models\ProveedorWorkflowStep;
use App\Models\ProveedorValidacion;
use App\Models\ProveedorHistorial;
use App\Services\AreaPermissionService;

class WorkflowController extends Controller
{
    protected $permissions;

    public function __construct(AreaPermissionService $permissions)
    {
        $this->permissions = $permissions;
    }

    /**
     * Ver workflow de un proveedor.
     *
     * GET /v1/workflow/proveedores/{id}
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
                'creadoPor',
                'usuarioProveedor',
                'workflowSteps',
            ])->findOrFail($id);

            if (!$this->permissions->canViewProveedor($user, $proveedor)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No tienes permisos para ver este workflow'
                ], 403);
            }

            /*
             * Importante:
             * AreaPermissionService filtra qué etapas puede ver cada usuario.
             * - Admin global ve todo.
             * - pm_user ve solo PM de sus proveedores.
             * - admin_area / usuario_area ve solo su área.
             */
            $steps = $proveedor->workflowSteps
                ->sortBy('sort_order')
                ->filter(function ($step) use ($user, $proveedor) {
                    return $this->permissions->canViewWorkflowStep($user, $proveedor, $step);
                })
                ->map(function ($step) {
                    return $this->mapStep($step);
                })
                ->values();

            return $this->responseData([
                'data' => [
                    'proveedor' => [
                        'id'          => (string) $proveedor->id,
                        'razonSocial' => $proveedor->razon_social,
                        'rut'         => $proveedor->rut,
                        'estado'      => $proveedor->estado,
                        'empresaId'   => $proveedor->empresa_id,
                    ],
                    'steps' => $steps,
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
     * Listar pendientes del usuario autenticado.
     *
     * GET /v1/workflow/mis-pendientes
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

            $query = ProveedorWorkflowStep::with([
                'proveedor',
            ])
                ->whereIn('status', ['Pendiente', 'En revisión', 'Observado'])
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'desc');

            /*
             * Admin global ve todos los pendientes.
             * pm_user legacy ve solo área PM y solo proveedores creados por él.
             * admin_area / usuario_area ve solo su área.
             */
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
                ->filter(function ($step) use ($user) {
                    if (!$step->proveedor) {
                        return false;
                    }

                    return $this->permissions->canViewWorkflowStep(
                        $user,
                        $step->proveedor,
                        $step
                    );
                })
                ->map(function ($step) {
                    return [
                        'step' => $this->mapStep($step),
                        'proveedor' => $step->proveedor ? [
                            'id'          => (string) $step->proveedor->id,
                            'razonSocial' => $step->proveedor->razon_social,
                            'rut'         => $step->proveedor->rut,
                            'estado'      => $step->proveedor->estado,
                            'empresaId'   => $step->proveedor->empresa_id,
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
     * Actualizar etapa workflow.
     *
     * PUT /v1/workflow/proveedores/{id}/steps/{stepId}
     */
    public function updateStep(Request $request, $id, $stepId)
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
                'workflowSteps',
                'validaciones',
            ])->findOrFail($id);

            $step = ProveedorWorkflowStep::where('proveedor_id', $proveedor->id)
                ->where('id', $stepId)
                ->firstOrFail();

            if (!$this->permissions->canUpdateWorkflowStep($user, $proveedor, $step)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No tienes permisos para actualizar esta etapa'
                ], 403);
            }

            /*
             * Una etapa aprobada no puede ser modificada por usuarios no globales.
             */
            if ($step->status === 'Aprobado' && !$this->permissions->isAdminGlobal($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'La etapa ya fue aprobada'
                ], 422);
            }

            DB::beginTransaction();
            $transactionStarted = true;

            $statusAnterior = $step->status;
            $nuevoStatus = $request->input('status');

            $step->status = $nuevoStatus;
            $step->comments = $request->input('comments');
            $step->updated_at = date('Y-m-d H:i:s');

            if (in_array($nuevoStatus, ['Aprobado', 'Rechazado', 'Observado'])) {
                $step->validated_by_user_id = $user->id;
                $step->validated_at = date('Y-m-d H:i:s');
            }

            /*
             * Si vuelve a Pendiente o En revisión, limpiamos validación formal.
             */
            if (in_array($nuevoStatus, ['Pendiente', 'En revisión'])) {
                $step->validated_by_user_id = null;
                $step->validated_at = null;
            }

            $step->save();

            ProveedorHistorial::create([
                'proveedor_id'  => $proveedor->id,
                'accion'        => 'Workflow ' . $step->step_name . ' cambió de ' . $statusAnterior . ' a ' . $nuevoStatus,
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $this->syncValidacion($step, (int) $user->id);
            $this->syncProveedorEstado($proveedor);

            DB::commit();

            return $this->responseData([
                'message' => 'Etapa actualizada',
                'data'    => $this->mapStep($step),
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
     * Reabrir etapa.
     *
     * Solo admin global puede reabrir.
     *
     * PUT /v1/workflow/proveedores/{id}/steps/{stepId}/reabrir
     */
    public function reabrirStep(Request $request, $id, $stepId)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->canReopenWorkflowStep($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $proveedor = Proveedores::findOrFail($id);

            $step = ProveedorWorkflowStep::where('proveedor_id', $proveedor->id)
                ->where('id', $stepId)
                ->firstOrFail();

            DB::beginTransaction();
            $transactionStarted = true;

            $statusAnterior = $step->status;

            $step->status = 'En revisión';
            $step->comments = $request->input('comments', $step->comments);
            $step->validated_by_user_id = null;
            $step->validated_at = null;
            $step->updated_at = date('Y-m-d H:i:s');
            $step->save();

            ProveedorHistorial::create([
                'proveedor_id'  => $proveedor->id,
                'accion'        => 'Workflow ' . $step->step_name . ' reabierto desde ' . $statusAnterior . ' a En revisión',
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $this->syncValidacion($step, null);
            $this->syncProveedorEstado($proveedor);

            DB::commit();

            return $this->responseData([
                'message' => 'Etapa reabierta',
                'data'    => $this->mapStep($step),
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

    /**
     * Espejo de la etapa en la validación del área. Validaciones y etapas del
     * workflow representan lo mismo; si se cambia una, se actualiza la otra
     * para que nunca se contradigan.
     */
    private function syncValidacion(ProveedorWorkflowStep $step, ?int $userId): void
    {
        $validacion = ProveedorValidacion::where('proveedor_id', $step->proveedor_id)
            ->where('area', $step->area)
            ->first();

        if (!$validacion) {
            return;
        }

        $validacion->status = $step->status;
        $validacion->comments = $step->comments;
        $validacion->updated_at = date('Y-m-d H:i:s');

        if (in_array($step->status, ['Aprobado', 'Rechazado', 'Observado'], true)) {
            $validacion->validated_by_user_id = $userId;
            $validacion->validated_at = date('Y-m-d H:i:s');
        } else {
            $validacion->validated_by_user_id = null;
            $validacion->validated_at = null;
        }

        $validacion->save();
    }

    private function mapStep(ProveedorWorkflowStep $step): array
    {
        return [
            'id'                  => (string) $step->id,
            'proveedorId'         => $step->proveedor_id ? (string) $step->proveedor_id : null,
            'stepKey'             => $step->step_key,
            'stepName'            => $step->step_name,
            'area'                => $step->area,
            'status'              => $step->status,
            'sortOrder'           => (int) $step->sort_order,
            'assignedUserId'      => $step->assigned_user_id ? (string) $step->assigned_user_id : null,
            'validatedByUserId'   => $step->validated_by_user_id ? (string) $step->validated_by_user_id : null,
            'validatedAt'         => $step->validated_at,
            'comments'            => $step->comments,
            'createdAt'           => $step->created_at,
            'updatedAt'           => $step->updated_at,
        ];
    }
}