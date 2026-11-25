<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Proveedores;
use App\Models\ProveedorDetalle;
use App\Models\ProveedorHistorial;
use App\Models\ProveedorSociedadHabilitada;

use App\Services\AreaPermissionService;
use App\Services\ProveedorDocumentosService;
use App\Services\ProveedorFichaPdfService;
use App\Services\ProveedorEstadoService;

class ProveedoresController extends Controller
{
    protected $permissions;
    protected $documentosService;
    protected $fichaPdfService;
    protected $estadoService;

    public function __construct(
        AreaPermissionService $permissions,
        ProveedorDocumentosService $documentosService,
        ProveedorFichaPdfService $fichaPdfService,
        ProveedorEstadoService $estadoService
    ) {
        $this->permissions = $permissions;
        $this->documentosService = $documentosService;
        $this->fichaPdfService = $fichaPdfService;
        $this->estadoService = $estadoService;
    }

    /**
     * Crear solicitud preliminar de proveedor.
     *
     * POST /v1/interno/proveedores/solicitud
     */
    public function crearSolicitudPreliminar(Request $request)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->canInviteProveedor($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado para crear solicitudes de proveedor',
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | Validación
            |--------------------------------------------------------------------------
            */

            $this->validate($request, [
                'emailProveedor' => 'required|email',
                'empresaIds' => 'required|array|min:1',
                'empresaIds.*' => 'required|string|distinct',
                'tipo' => 'required|in:Nacional,Internacional',
                'tipoProveedorDetalle' => 'required|string',
                'razonSocial' => 'nullable|string',
                'rut' => 'nullable|string',
                'giro' => 'nullable|string',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Validar tipo de proveedor
            |--------------------------------------------------------------------------
            */

            $tipoProveedorDetalle = trim(
                (string) $request->input('tipoProveedorDetalle')
            );

            $tiposProveedorPermitidos = [
                'Proveedor de servicios',
                'Proveedor de productos nacional',
                'Proveedor de productos internacional',
            ];

            if (
                !in_array(
                    $tipoProveedorDetalle,
                    $tiposProveedorPermitidos,
                    true
                )
            ) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Tipo de proveedor no válido',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Normalizar empresas seleccionadas por el PM
            |--------------------------------------------------------------------------
            */

            $empresaIdsSolicitadas = $request->input('empresaIds', []);

            $empresaIdsSolicitadas = array_map(
                function ($empresaId) {
                    return trim((string) $empresaId);
                },
                $empresaIdsSolicitadas
            );

            $empresaIdsSolicitadas = array_filter(
                $empresaIdsSolicitadas,
                function ($empresaId) {
                    return $empresaId !== '';
                }
            );

            $empresaIdsSolicitadas = array_values(
                array_unique($empresaIdsSolicitadas)
            );

            if (count($empresaIdsSolicitadas) === 0) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Debes seleccionar al menos una empresa.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Verificar que todas las empresas existan
            |--------------------------------------------------------------------------
            */

            $empresas = DB::table('empresas')
                ->whereIn('id', $empresaIdsSolicitadas)
                ->get();

            $empresaIdsExistentes = $empresas
                ->pluck('id')
                ->map(function ($empresaId) {
                    return (string) $empresaId;
                })
                ->values()
                ->all();

            $empresaIdsInvalidas = array_values(
                array_diff(
                    $empresaIdsSolicitadas,
                    $empresaIdsExistentes
                )
            );

            if (count($empresaIdsInvalidas) > 0) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Una o más empresas seleccionadas no existen.',
                    'empresaIdsInvalidas' => $empresaIdsInvalidas,
                ], 422);
            }

            /*
             * La primera empresa seleccionada queda como empresa principal.
             */
            $empresaPrincipalId = $empresaIdsSolicitadas[0];

            $empresasPorId = $empresas->keyBy(function ($empresa) {
                return (string) $empresa->id;
            });

            /*
            |--------------------------------------------------------------------------
            | Iniciar transacción
            |--------------------------------------------------------------------------
            */

            DB::beginTransaction();
            $transactionStarted = true;

            $now = date('Y-m-d H:i:s');

            $razonSocial = trim(
                (string) (
                    $request->input('razonSocial')
                        ?: 'Proveedor pendiente de activación'
                )
            );

            $rut = trim(
                (string) (
                    $request->input('rut')
                        ?: 'Pendiente'
                )
            );

            $giro = trim(
                (string) (
                    $request->input('giro')
                        ?: 'Pendiente'
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Crear proveedor preliminar
            |--------------------------------------------------------------------------
            */

            $proveedor = Proveedores::create([
                'razon_social' => $razonSocial,
                'rut' => $rut,
                'giro' => $giro,
                'tipo' => $request->input('tipo'),
                'empresa_id' => $empresaPrincipalId,
                'estado' => 'En revisión',
                'proveedor_user_id' => null,
                'created_by_user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Guardar tipo de proveedor definido por el PM
            |--------------------------------------------------------------------------
            */

            $detalle = new ProveedorDetalle();
            $detalle->proveedor_id = $proveedor->id;
            $detalle->tipo_proveedor_detalle = $tipoProveedorDetalle;
            $detalle->created_at = $now;
            $detalle->updated_at = $now;
            $detalle->save();

            /*
            |--------------------------------------------------------------------------
            | Guardar empresas como sociedades habilitadas
            |--------------------------------------------------------------------------
            */

            foreach ($empresaIdsSolicitadas as $empresaId) {
                $empresa = $empresasPorId->get($empresaId);

                if (!$empresa) {
                    throw new \Exception(
                        'No se pudo obtener la empresa con ID ' . $empresaId
                    );
                }

                ProveedorSociedadHabilitada::create([
                    'proveedor_id' => $proveedor->id,
                    'sociedad_nombre' => $empresa->nombre,
                    'created_at' => $now,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Crear documentos obligatorios del solicitante
            |--------------------------------------------------------------------------
            */

            $this->documentosService
                ->crearDocumentosSolicitante($proveedor->id);

            /*
            |--------------------------------------------------------------------------
            | Historial
            |--------------------------------------------------------------------------
            */

            $nombresEmpresas = [];

            foreach ($empresaIdsSolicitadas as $empresaId) {
                $empresa = $empresasPorId->get($empresaId);

                if ($empresa) {
                    $nombresEmpresas[] = $empresa->nombre;
                }
            }

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Solicitud preliminar creada. Pendiente carga de documentos del solicitante.',
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at' => $now,
            ]);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Empresas definidas por el PM: ' .
                    implode(', ', $nombresEmpresas),
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at' => $now,
            ]);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Tipo de proveedor definido por el PM: ' .
                    $tipoProveedorDetalle,
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at' => $now,
            ]);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Documentos obligatorios del solicitante creados: Declaración de conflicto de interés y Autorización de jefatura',
                'realizado_por' => 'Sistema',
                'created_at' => $now,
            ]);

            DB::commit();
            $transactionStarted = false;

            /*
            |--------------------------------------------------------------------------
            | Recargar proveedor con relaciones
            |--------------------------------------------------------------------------
            */

            $proveedorCreado = Proveedores::with(
                $this->proveedorRelations()
            )->findOrFail($proveedor->id);

            return $this->responseData([
                'message' => 'Solicitud preliminar creada correctamente',
                'data' => $this->mapProveedor(
                    $proveedorCreado,
                    'interno'
                ),
            ], 201);
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
     * Listar proveedores internos.
     *
     * GET /v1/interno/proveedores
     */
    public function index(Request $request)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $query = Proveedores::with(
                $this->proveedorRelations()
            )->orderBy('id', 'desc');

            $this->permissions
                ->applyProveedorVisibilityQuery($query, $user);

            $proveedores = $query
                ->get()
                ->map(function ($proveedor) {
                    return $this->mapProveedor(
                        $proveedor,
                        'interno'
                    );
                });

            return $this->responseData([
                'data' => $proveedores,
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar solicitudes propias del proveedor.
     *
     * GET /v1/proveedor/solicitudes
     */
    public function misSolicitudes(Request $request)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->isProveedor($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Solo proveedores pueden ver sus solicitudes',
                ], 403);
            }

            $proveedores = Proveedores::with(
                $this->proveedorRelations()
            )
                ->where('proveedor_user_id', $user->id)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($proveedor) {
                    return $this->mapProveedor(
                        $proveedor,
                        'proveedor'
                    );
                });

            return $this->responseData([
                'data' => $proveedores,
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cambiar estado general del proveedor.
     *
     * PUT /v1/interno/proveedores/{id}/estado
     */
    public function updateEstado(Request $request, $id)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $this->validate($request, [
                'estado' => 'required|string',
            ]);

            $estado = $request->input('estado');

            $estadosValidos = [
                'En revisión',
                'Observado',
                'Rechazado',
                'Aprobado',
            ];

            if (!in_array($estado, $estadosValidos, true)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Estado no válido',
                ], 422);
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            if (
                !$this->permissions->canUpdateProveedorEstado(
                    $user,
                    $proveedor
                )
            ) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No puedes modificar el estado general de este proveedor',
                ], 403);
            }

            /*
             * El estado general no se fija a mano: lo calcula una sola regla a
             * partir de las validaciones por área y los documentos. Aquí solo se
             * acepta el valor que esa regla ya entrega; para cambiarlo hay que
             * cambiar la validación del área o el documento correspondiente.
             */
            $estadoCalculado = $this->estadoService->calcularEstado($proveedor->id);

            if ($estado !== $estadoCalculado) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'El estado general se calcula a partir de las validaciones por área y los documentos '
                        . '(ahora corresponde "' . $estadoCalculado . '"). Para cambiarlo, modifique la validación '
                        . 'del área o el documento correspondiente.',
                    'estadoCalculado' => $estadoCalculado,
                ], 422);
            }

            DB::beginTransaction();
            $transactionStarted = true;

            $estadoAnterior = $proveedor->estado;

            $proveedor->estado = $estado;
            $proveedor->updated_at = date('Y-m-d H:i:s');
            $proveedor->save();

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Estado cambiado manualmente de ' .
                    $estadoAnterior .
                    ' a ' .
                    $estado,
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            DB::commit();
            $transactionStarted = false;

            $proveedorActualizado =
                $this->findProveedorVisibleOrFail(
                    $id,
                    $user
                );

            return $this->responseData([
                'message' => 'Estado actualizado correctamente',
                'data' => $this->mapProveedor(
                    $proveedorActualizado,
                    'interno'
                ),
            ], 200);
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
     * Regenerar ficha PDF.
     *
     * POST /v1/interno/proveedores/{id}/ficha-pdf
     */
    public function generarFichaPdf(Request $request, $id)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            DB::beginTransaction();
            $transactionStarted = true;

            $archivoUrl = $this->fichaPdfService
                ->generarYRegistrarFicha($proveedor->id);

            $nuevoEstado = $this->estadoService
                ->recalcularEstadoProveedor($proveedor->id);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Ficha de incorporación proveedor regenerada automáticamente',
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Estado general del proveedor recalculado a ' .
                    $nuevoEstado,
                'realizado_por' => 'Sistema',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            DB::commit();
            $transactionStarted = false;

            $proveedorActualizado =
                $this->findProveedorVisibleOrFail(
                    $id,
                    $user
                );

            return $this->responseData([
                'message' => 'Ficha PDF generada correctamente',
                'archivoUrl' => $archivoUrl,
                'data' => $this->mapProveedor(
                    $proveedorActualizado,
                    'interno'
                ),
            ], 200);
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
     * Recalcular el estado general con la regla única (validaciones por área y documentos).
     *
     * POST /v1/interno/proveedores/{id}/recalcular-estado
     */
    public function recalcularEstado(Request $request, $id)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            DB::beginTransaction();
            $transactionStarted = true;

            $nuevoEstado = $this->estadoService
                ->recalcularEstadoProveedor($proveedor->id);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Estado general del proveedor recalculado manualmente a ' .
                    $nuevoEstado,
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            DB::commit();
            $transactionStarted = false;

            $proveedorActualizado =
                $this->findProveedorVisibleOrFail(
                    $id,
                    $user
                );

            return $this->responseData([
                'message' => 'Estado recalculado correctamente',
                'estado' => $nuevoEstado,
                'resumenDocumental' => $this->estadoService
                    ->obtenerResumenDocumental($proveedor->id),
                'data' => $this->mapProveedor(
                    $proveedorActualizado,
                    'interno'
                ),
            ], 200);
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
     * Crear proveedor manual.
     */
    public function crear(Request $request)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->isAdminGlobal($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Solo admin global puede crear proveedores',
                ], 403);
            }

            $this->validate($request, [
                'razon_social' => 'required|string',
                'rut' => 'required|string',
                'tipo' => 'required|in:Nacional,Internacional',
                'empresa_id' => 'required|string',
                'giro' => 'required|string',
            ]);

            $empresaId = $this->resolveEmpresaId(
                $request->input('empresa_id')
            );

            if (!$empresaId) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Empresa no válida',
                ], 422);
            }

            $proveedor = Proveedores::create([
                'razon_social' => trim(
                    $request->input('razon_social')
                ),
                'rut' => trim(
                    $request->input('rut')
                ),
                'giro' => trim(
                    $request->input('giro')
                ),
                'tipo' => $request->input('tipo'),
                'empresa_id' => $empresaId,
                'estado' => 'En revisión',
                'created_by_user_id' => $user->id,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Proveedor creado manualmente',
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $proveedorCreado = Proveedores::with(
                $this->proveedorRelations()
            )->findOrFail($proveedor->id);

            return $this->responseData([
                'message' => 'Proveedor creado correctamente',
                'data' => $this->mapProveedor(
                    $proveedorCreado,
                    'interno'
                ),
            ], 201);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de búsqueda y visibilidad
    |--------------------------------------------------------------------------
    */

    private function proveedorRelations(): array
    {
        return [
            'usuarioProveedor',
            'creadoPor',
            'documentos',
            'historial',
            'detalle',
            'sociedadesHabilitadas',
            'workflowSteps',
            'validaciones',
        ];
    }

    private function findProveedorVisibleOrFail($id, $user)
    {
        $query = Proveedores::with(
            $this->proveedorRelations()
        )->where('id', $id);

        $this->permissions
            ->applyProveedorVisibilityQuery($query, $user);

        return $query->firstOrFail();
    }

    private function resolveEmpresaId(string $empresaId): ?string
    {
        $raw = trim($empresaId);

        if ($raw === '') {
            return null;
        }

        $candidates = array_values(array_unique([
            $raw,
            strtoupper($raw),
            strtolower($raw),
        ]));

        $empresa = DB::table('empresas')
            ->whereIn('id', $candidates)
            ->first();

        return $empresa ? (string) $empresa->id : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Mappers
    |--------------------------------------------------------------------------
    */

    private function mapProveedor(
        $proveedor,
        string $context = 'interno'
    ): array {
        $canUploadDocuments = $this->documentosService
            ->proveedorPuedeSubirDocumentos($proveedor->id);

        $pmPrerequisitesPending = $this->documentosService
            ->obtenerPrerequisitosPendientes($proveedor->id);

        $resumenDocumental = $this->estadoService
            ->obtenerResumenDocumental($proveedor->id);

        return [
            'id' => (string) $proveedor->id,
            'razonSocial' => $proveedor->razon_social,
            'rut' => $proveedor->rut,
            'giro' => $proveedor->giro,
            'tipo' => $proveedor->tipo,

            'empresaId' => $proveedor->empresa_id,
            'empresaNombre' => $this->getEmpresaNombre(
                $proveedor->empresa_id
            ),

            'estado' => $proveedor->estado,

            'canUploadDocuments' => $canUploadDocuments,
            'pmPrerequisitesPending' => $pmPrerequisitesPending,
            'resumenDocumental' => $resumenDocumental,

            'canInviteProveedor' => $this->documentosService
                ->puedeInvitarProveedor($proveedor->id),

            'createdById' => $proveedor->created_by_user_id
                ? (string) $proveedor->created_by_user_id
                : null,

            'createdByEmail' => $proveedor->creadoPor
                ? $proveedor->creadoPor->email
                : null,

            'createdByName' => $proveedor->creadoPor
                ? $proveedor->creadoPor->display_name
                : null,

            'proveedorUserId' => $proveedor->proveedor_user_id
                ? (string) $proveedor->proveedor_user_id
                : null,

            'proveedorEmail' => $proveedor->usuarioProveedor
                ? $proveedor->usuarioProveedor->email
                : null,

            'proveedorNombre' => $proveedor->usuarioProveedor
                ? $proveedor->usuarioProveedor->display_name
                : null,

            'detalle' => $this->mapDetalle(
                $proveedor->detalle
            ),

            'sociedadesHabilitadas' => $this->mapSociedades(
                $proveedor
            ),

            'documentos' => $this->mapDocumentos(
                $proveedor,
                $context
            ),

            'workflow' => $this->mapWorkflow(
                $proveedor
            ),

            'workflowSteps' => $this->mapWorkflow(
                $proveedor
            ),

            'validaciones' => $this->mapValidaciones(
                $proveedor
            ),

            'historial' => $this->mapHistorial(
                $proveedor
            ),

            'createdAt' => $proveedor->created_at,
            'updatedAt' => $proveedor->updated_at,
        ];
    }

    private function mapDetalle($detalle): ?array
    {
        if (!$detalle) {
            return null;
        }

        return [
            'id' => (string) $detalle->id,
            'direccion' => $detalle->direccion,
            'comuna' => $detalle->comuna,
            'region' => $detalle->region,
            'codigoPostal' => $detalle->codigo_postal,
            'telefono' => $detalle->telefono,
            'moneda' => $detalle->moneda,
            'pais' => $detalle->pais,

            'representanteLegal' =>
                $detalle->representante_legal,

            'representanteCi' =>
                $detalle->representante_ci,

            'representanteDireccion' =>
                $detalle->representante_direccion,

            'representanteEmail' =>
                $detalle->representante_email,

            'representanteCargo' =>
                $detalle->representante_cargo,

            'personaContacto' =>
                $detalle->persona_contacto,

            'personaContactoCargo' =>
                $detalle->persona_contacto_cargo,

            'personaContactoTelefono' =>
                $detalle->persona_contacto_telefono,

            'personaContactoEmail' =>
                $detalle->persona_contacto_email,

            'fechaEscrituraPublica' =>
                $detalle->fecha_escritura_publica,

            'notaria' => $detalle->notaria,
            'plazoPago' => $detalle->plazo_pago,
            'formaPago' => $detalle->forma_pago,
            'diasDespacho' => $detalle->dias_despacho,
            'bancoPago' => $detalle->banco_pago,
            'cuentaCorriente' => $detalle->cuenta_corriente,

            'dimensionNegocioTexto' =>
                $detalle->dimension_negocio_texto,

            'tipoProveedorDetalle' =>
                $detalle->tipo_proveedor_detalle,

            'createdAt' => $detalle->created_at,
            'updatedAt' => $detalle->updated_at,
        ];
    }

    private function mapSociedades($proveedor): array
    {
        if (!$proveedor->sociedadesHabilitadas) {
            return [];
        }

        return $proveedor->sociedadesHabilitadas
            ->map(function ($sociedad) {
                return [
                    'id' => (string) $sociedad->id,
                    'nombre' => $sociedad->sociedad_nombre,
                    'sociedadNombre' => $sociedad->sociedad_nombre,
                    'createdAt' => $sociedad->created_at,
                ];
            })
            ->values()
            ->toArray();
    }

    private function mapDocumentos(
        $proveedor,
        string $context = 'interno'
    ): array {
        if (!$proveedor->documentos) {
            return [];
        }

        $documentos = $proveedor->documentos;

        if ($context === 'proveedor') {
            $documentos = $documentos->filter(
                function ($documento) {
                    if (
                        (int) $documento->visible_para_proveedor !== 1
                    ) {
                        return false;
                    }

                    if (
                        $documento->responsable_carga === 'pm'
                    ) {
                        return false;
                    }

                    return true;
                }
            );
        }

        if ($context === 'interno') {
            $documentos = $documentos->filter(
                function ($documento) {
                    return (
                        (int) $documento->visible_para_internos === 1
                    );
                }
            );
        }

        return $documentos
            ->sortBy('id')
            ->map(function ($documento) {
                return [
                    'id' => (string) $documento->id,

                    'proveedorId' => $documento->proveedor_id
                        ? (string) $documento->proveedor_id
                        : null,

                    'nombre' => $documento->nombre,
                    'estado' => $documento->estado,

                    'responsableCarga' =>
                        $documento->responsable_carga,

                    'areaResponsable' =>
                        $documento->area_responsable,

                    'esPrerrequisito' =>
                        (bool) $documento->es_prerrequisito,

                    'bloqueaCargaProveedor' =>
                        (bool) $documento->bloquea_carga_proveedor,

                    'visibleParaProveedor' =>
                        (bool) $documento->visible_para_proveedor,

                    'visibleParaInternos' =>
                        (bool) $documento->visible_para_internos,

                    'plantillaUrl' =>
                        $documento->plantilla_url,

                    'archivoNombre' =>
                        $documento->archivo_nombre,

                    'archivoUrl' =>
                        $documento->archivo_url,

                    'archivoMimeType' =>
                        $documento->archivo_mime_type,

                    'uploadedByUserId' =>
                        $documento->uploaded_by_user_id
                            ? (string) $documento->uploaded_by_user_id
                            : null,

                    'comentario' =>
                        $documento->comentario,

                    'reviewedByUserId' =>
                        $documento->reviewed_by_user_id
                            ? (string) $documento->reviewed_by_user_id
                            : null,

                    'reviewedAt' =>
                        $documento->reviewed_at,

                    'createdAt' =>
                        $documento->created_at,

                    'updatedAt' =>
                        $documento->updated_at,
                ];
            })
            ->values()
            ->toArray();
    }

    private function mapWorkflow($proveedor): array
    {
        if (!$proveedor->workflowSteps) {
            return [];
        }

        return $proveedor->workflowSteps
            ->sortBy('sort_order')
            ->map(function ($step) {
                return [
                    'id' => (string) $step->id,

                    'proveedorId' => $step->proveedor_id
                        ? (string) $step->proveedor_id
                        : null,

                    'stepKey' => $step->step_key,
                    'stepName' => $step->step_name,
                    'area' => $step->area,
                    'status' => $step->status,
                    'sortOrder' => (int) $step->sort_order,

                    'assignedUserId' => $step->assigned_user_id
                        ? (string) $step->assigned_user_id
                        : null,

                    'validatedByUserId' =>
                        $step->validated_by_user_id
                            ? (string) $step->validated_by_user_id
                            : null,

                    'validatedAt' => $step->validated_at,
                    'comments' => $step->comments,
                    'createdAt' => $step->created_at,
                    'updatedAt' => $step->updated_at,
                ];
            })
            ->values()
            ->toArray();
    }

    private function mapValidaciones($proveedor): array
    {
        if (!$proveedor->validaciones) {
            return [];
        }

        return $proveedor->validaciones
            ->map(function ($validacion) {
                return [
                    'id' => (string) $validacion->id,

                    'proveedorId' => $validacion->proveedor_id
                        ? (string) $validacion->proveedor_id
                        : null,

                    'area' => $validacion->area,
                    'areaName' => $validacion->area_name,
                    'status' => $validacion->status,

                    'validatedByUserId' =>
                        $validacion->validated_by_user_id
                            ? (string) $validacion->validated_by_user_id
                            : null,

                    'validatedAt' =>
                        $validacion->validated_at,

                    'comments' => $validacion->comments,
                    'createdAt' => $validacion->created_at,
                    'updatedAt' => $validacion->updated_at,
                ];
            })
            ->values()
            ->toArray();
    }

    private function mapHistorial($proveedor): array
    {
        if (!$proveedor->historial) {
            return [];
        }

        return $proveedor->historial
            ->sortByDesc('id')
            ->map(function ($historial) {
                return [
                    'id' => (string) $historial->id,

                    'proveedorId' => $historial->proveedor_id
                        ? (string) $historial->proveedor_id
                        : null,

                    'accion' => $historial->accion,
                    'realizadoPor' => $historial->realizado_por,
                    'fecha' => $historial->created_at,
                    'createdAt' => $historial->created_at,
                ];
            })
            ->values()
            ->toArray();
    }

    private function getEmpresaNombre(
        ?string $empresaId
    ): ?string {
        if (!$empresaId) {
            return null;
        }

        $empresa = DB::table('empresas')
            ->where('id', $empresaId)
            ->first();

        return $empresa
            ? $empresa->nombre
            : null;
    }
}
