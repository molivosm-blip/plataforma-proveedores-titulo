<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\User;
use App\Models\Proveedores;
use App\Models\ProveedorDetalle;
use App\Models\ProveedorHistorial;
use App\Models\ProveedorInvitation;
use App\Models\ProveedorWorkflowStep;
use App\Models\ProveedorValidacion;

use App\Services\AuthService;
use App\Services\AreaPermissionService;
use App\Services\ProveedorDocumentosService;
use App\Services\ProveedorFichaPdfService;

class ProveedorInvitationsController extends Controller
{
    protected $authService;
    protected $permissions;
    protected $documentosService;
    protected $fichaPdfService;

    public function __construct(
        AuthService $authService,
        AreaPermissionService $permissions,
        ProveedorDocumentosService $documentosService,
        ProveedorFichaPdfService $fichaPdfService
    ) {
        $this->authService = $authService;
        $this->permissions = $permissions;
        $this->documentosService = $documentosService;
        $this->fichaPdfService = $fichaPdfService;
    }

    /**
     * Crear invitación para proveedor.
     *
     * Debe existir un proveedor preliminar y los documentos
     * obligatorios del solicitante deben estar cargados.
     *
     * POST /v1/interno/proveedor-invitations
     */
    public function store(Request $request)
    {
        try {
           $user = $request->auth;

           if (!$this->permissions->canInviteProveedor($user)) {
               return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado para crear invitaciones',
                ], 403);
            }

            $this->validate($request, [
                'email' => 'required|email',
                'proveedorId' => 'required',
            ]);

            $email = trim(
                strtolower((string) $request->input('email'))
            );

            $proveedorId = $request->input('proveedorId');

            $proveedor = Proveedores::with([
                'detalle',
                'sociedadesHabilitadas',
            ])->findOrFail($proveedorId);

            if (!$this->permissions->canViewProveedor($user, $proveedor)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No puedes invitar sobre este proveedor',
                ], 403);
            }

            if (
                !$this->documentosService
                    ->puedeInvitarProveedor($proveedor->id)
            ) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Debes subir la Declaración de conflicto de interés del solicitante y la Autorización de jefatura antes de invitar al proveedor.',
                    'pmPrerequisitesPending' => $this->documentosService
                        ->obtenerPrerequisitosPendientes($proveedor->id),
                ], 422);
            }

            $pendingInvite = ProveedorInvitation::where(
                'email',
                $email
            )
                ->where('status', 'pending')
                ->first();

            if ($pendingInvite) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Ya existe una invitación pendiente para este correo',
                ], 422);
            }

            $token = $this->generateToken();
            $now = date('Y-m-d H:i:s');

            $invitation = new ProveedorInvitation();
            $invitation->proveedor_id = $proveedor->id;
            $invitation->email = $email;
            $invitation->token = $token;
            $invitation->status = 'pending';
            $invitation->created_by_user_id = $user->id;
            $invitation->created_at = $now;
            $invitation->save();

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Invitación enviada al proveedor ' . $email,
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at' => $now,
            ]);

            $activationLink = $this->buildActivationLink(
                $request,
                $token
            );

            return $this->responseData([
                'message' => 'Invitación creada correctamente',
                'data' => $this->mapInvitation(
                    $invitation,
                    $activationLink
                ),
            ], 201);
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
     * Listar invitaciones.
     *
     * GET /v1/interno/proveedor-invitations
     */
    public function index(Request $request)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->canInviteProveedor($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $query = ProveedorInvitation::with('creadoPor')
                ->orderBy('id', 'desc');

            if (!$this->permissions->isAdminGlobal($user)) {
                $query->where(
                    'created_by_user_id',
                    $user->id
                );
            }

            $items = $query
                ->get()
                ->map(function ($invitation) use ($request) {
                    return $this->mapInvitation(
                        $invitation,
                        $this->buildActivationLink(
                            $request,
                            $invitation->token
                        )
                    );
                });

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
     * Revocar invitación.
     *
     * PUT /v1/interno/proveedor-invitations/{id}/revoke
     */
    public function revoke(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->canInviteProveedor($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $invitation = ProveedorInvitation::findOrFail($id);

            if (
                !$this->permissions->isAdminGlobal($user) &&
                (int) $invitation->created_by_user_id !==
                    (int) $user->id
            ) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No puedes revocar una invitación que no creaste',
                ], 403);
            }

            if ($invitation->status !== 'pending') {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Solo se pueden revocar invitaciones pendientes',
                ], 422);
            }

            $invitation->status = 'revoked';
            $invitation->revoked_at = date('Y-m-d H:i:s');
            $invitation->save();

            if ($invitation->proveedor_id) {
                ProveedorHistorial::create([
                    'proveedor_id' => $invitation->proveedor_id,
                    'accion' => 'Invitación revocada para ' .
                        $invitation->email,
                    'realizado_por' => $user->display_name
                        ?: $user->email,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $this->responseData([
                'message' => 'Invitación revocada correctamente',
                'data' => $this->mapInvitation($invitation),
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
     * Público.
     *
     * Obtiene la invitación y los datos preliminares definidos
     * por el PM para precargar el formulario.
     *
     * No expone empresa_id ni sociedades habilitadas al proveedor.
     *
     * GET /v1/proveedor/invitations/{token}
     */
    public function showByToken(Request $request, $token)
    {
        try {
            $invitation = ProveedorInvitation::with('creadoPor')
                ->where('token', $token)
                ->first();

            if (!$invitation) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Invitación no encontrada',
                ], 404);
            }

            $data = $this->mapInvitation($invitation);

            if ($invitation->proveedor_id) {
                $proveedor = Proveedores::with([
                    'detalle',
                ])->find($invitation->proveedor_id);

                if ($proveedor) {
                    $tipoProveedorDetalle = null;

                    if ($proveedor->detalle) {
                        $tipoProveedorDetalle =
                            $proveedor
                                ->detalle
                                ->tipo_proveedor_detalle;
                    }

                    $data['proveedor'] = [
                        'id' => (string) $proveedor->id,
                        'razonSocial' => $proveedor->razon_social,
                        'rut' => $proveedor->rut,
                        'giro' => $proveedor->giro,
                        'tipo' => $proveedor->tipo,
                        'tipoProveedorDetalle' =>
                            $tipoProveedorDetalle,
                    ];
                }
            }

            return $this->responseData([
                'data' => $data,
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
     * Público.
     *
     * Completa y activa un proveedor preliminar.
     *
     * Las empresas, las sociedades habilitadas y el tipo de proveedor
     * fueron definidos previamente por el PM y no se leen desde el
     * formulario público.
     *
     * POST /v1/proveedor/activar
     */
    public function activate(Request $request)
    {
        $transactionStarted = false;

        try {
            /*
            |--------------------------------------------------------------------------
            | Validación del formulario público
            |--------------------------------------------------------------------------
            */

            $this->validate($request, [
                'token' => 'required|string',
                'password' => 'required|string|min:8|max:72',

                'razonSocial' => 'required|string',
                'rut' => 'required|string',
                'tipo' => 'required|in:Nacional,Internacional',
                'giro' => 'required|string',
                'proveedorNombre' => 'nullable|string',

                'direccion' => 'required|string',
                'comuna' => 'required|string',
                'region' => 'nullable|string',
                'codigoPostal' => 'nullable|string',
                'telefono' => 'nullable|string',
                'moneda' => 'required|string',
                'pais' => 'required|string',

                'representanteLegal' => 'nullable|string',
                'representanteCi' => 'nullable|string',
                'representanteDireccion' => 'nullable|string',
                'representanteEmail' => 'nullable|email',
                'representanteCargo' => 'nullable|string',

                'personaContacto' => 'nullable|string',
                'personaContactoCargo' => 'nullable|string',
                'personaContactoTelefono' => 'nullable|string',
                'personaContactoEmail' => 'nullable|email',

                'fechaEscrituraPublica' => 'nullable|date',
                'notaria' => 'nullable|string',

                'plazoPago' => 'required|string',
                'formaPago' => 'required|string',
                'diasDespacho' => 'nullable|string',
                'bancoPago' => 'required|string',
                'cuentaCorriente' => 'required|string',

                'dimensionNegocioTexto' => 'nullable|string',
            ]);

            if (
                $request->input('tipo') === 'Nacional'
                && !app(\App\Services\RutService::class)->esValido($request->input('rut'))
            ) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'El RUT no es válido. Revise el número y el dígito verificador.',
                ], 422);
            }

            $tiposProveedorPermitidos = [
                'Proveedor de servicios',
                'Proveedor de productos nacional',
                'Proveedor de productos internacional',
            ];

            DB::beginTransaction();
            $transactionStarted = true;

            $now = date('Y-m-d H:i:s');
            $token = $request->input('token');

            /*
            |--------------------------------------------------------------------------
            | Invitación
            |--------------------------------------------------------------------------
            */

            $invitation = ProveedorInvitation::where(
                'token',
                $token
            )->first();

            if (!$invitation) {
                throw new \Exception(
                    'Invitación no encontrada'
                );
            }

            if ($invitation->status !== 'pending') {
                throw new \Exception(
                    'La invitación ya fue utilizada o fue revocada'
                );
            }

            if (!$invitation->proveedor_id) {
                throw new \Exception(
                    'La invitación no está asociada a una solicitud de proveedor. Debes crear una nueva invitación desde una solicitud preliminar.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Proveedor preliminar
            |--------------------------------------------------------------------------
            */

            $proveedor = Proveedores::with([
                'detalle',
                'sociedadesHabilitadas',
            ])->findOrFail($invitation->proveedor_id);

            if (
                !$this->documentosService
                    ->puedeInvitarProveedor($proveedor->id)
            ) {
                throw new \Exception(
                    'No se puede activar el proveedor porque faltan documentos obligatorios del solicitante.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Empresa y tipo de proveedor definidos por el PM
            |--------------------------------------------------------------------------
            */

            $empresaId = $proveedor->empresa_id;

            if (!$empresaId) {
                throw new \Exception(
                    'La solicitud preliminar no tiene una empresa principal asignada.'
                );
            }

            $detallePreliminar = $proveedor->detalle;

            if (!$detallePreliminar) {
                $detallePreliminar = ProveedorDetalle::where(
                    'proveedor_id',
                    $proveedor->id
                )->first();
            }

            $tipoProveedorDetalle = $detallePreliminar
                ? $detallePreliminar->tipo_proveedor_detalle
                : null;

            if (
                !$tipoProveedorDetalle ||
                !in_array(
                    $tipoProveedorDetalle,
                    $tiposProveedorPermitidos,
                    true
                )
            ) {
                throw new \Exception(
                    'La solicitud preliminar no tiene un tipo de proveedor válido.'
                );
            }

            $sociedadesDefinidas = DB::table(
                'proveedor_sociedades_habilitadas'
            )
                ->where('proveedor_id', $proveedor->id)
                ->pluck('sociedad_nombre')
                ->filter()
                ->values()
                ->all();

            if (count($sociedadesDefinidas) === 0) {
                throw new \Exception(
                    'La solicitud preliminar no tiene empresas o sociedades habilitadas.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Datos de usuario
            |--------------------------------------------------------------------------
            */

            $email = trim(
                strtolower($invitation->email)
            );

            $displayName = trim(
                $request->input('proveedorNombre')
                    ?: $request->input('razonSocial')
            );

            $plainPassword = $request->input('password');

            /*
            |--------------------------------------------------------------------------
            | Crear o actualizar usuario proveedor
            |--------------------------------------------------------------------------
            */

            $user = User::where('email', $email)->first();

            if ($user) {
                if ($user->role !== 'proveedor') {
                    throw new \Exception(
                        'El correo ya pertenece a un usuario interno'
                    );
                }

                if (!empty($user->password_hash)) {
                    if (
                        !$this->authService->passwordMatches(
                            $plainPassword,
                            $user->password_hash
                        )
                    ) {
                        throw new \Exception(
                            'La contraseña no coincide con el usuario proveedor existente'
                        );
                    }

                    if (
                        $this->authService->isLegacyMd5Hash(
                            $user->password_hash
                        )
                    ) {
                        // Contraseña que el usuario ya tenía: solo cambia el algoritmo.
                        $user->password_hash =
                            $this->authService->hashPassword(
                                $plainPassword,
                                $email,
                                false
                            );
                    }
                } else {
                    $user->password_hash =
                        $this->authService->hashPassword(
                            $plainPassword,
                            $email
                        );
                }

                $user->auth_type = 'local';
                $user->display_name = $displayName;
                $user->active = 1;
                $user->save();
            } else {
                $user = User::create([
                    'email' => $email,
                    'password_hash' =>
                        $this->authService->hashPassword(
                            $plainPassword,
                            $email
                        ),
                    'display_name' => $displayName,
                    'role' => 'proveedor',
                    'auth_type' => 'local',
                    'area' => null,
                    'active' => 1,
                    'created_at' => $now,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validar duplicado para usuario y empresa principal
            |--------------------------------------------------------------------------
            */

            $alreadyExists = Proveedores::where(
                'proveedor_user_id',
                $user->id
            )
                ->where('empresa_id', $empresaId)
                ->where('id', '<>', $proveedor->id)
                ->first();

            if ($alreadyExists) {
                throw new \Exception(
                    'Ya existe un proceso de proveedor para esta empresa'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Completar proveedor preliminar
            |--------------------------------------------------------------------------
            |
            | La empresa principal se conserva desde la solicitud del PM.
            |--------------------------------------------------------------------------
            */

            $proveedor->razon_social = trim(
                $request->input('razonSocial')
            );

            $proveedor->rut = trim(
                $request->input('rut')
            );

            $proveedor->giro = trim(
                $request->input('giro')
            );

            $proveedor->tipo = $request->input('tipo');
            $proveedor->empresa_id = $empresaId;
            $proveedor->estado = 'En revisión';
            $proveedor->proveedor_user_id = $user->id;
            $proveedor->updated_at = $now;
            $proveedor->save();

            /*
            |--------------------------------------------------------------------------
            | Crear o actualizar detalle del proveedor
            |--------------------------------------------------------------------------
            */

            $detalle = ProveedorDetalle::where(
                'proveedor_id',
                $proveedor->id
            )->first();

            if (!$detalle) {
                $detalle = new ProveedorDetalle();
                $detalle->proveedor_id = $proveedor->id;
                $detalle->created_at = $now;
            }

            $detalle->direccion =
                $request->input('direccion');

            $detalle->comuna =
                $request->input('comuna');

            $detalle->region =
                $request->input('region');

            $detalle->codigo_postal =
                $request->input('codigoPostal');

            $detalle->telefono =
                $request->input('telefono');

            $detalle->moneda =
                $request->input('moneda');

            $detalle->pais =
                $request->input('pais');

            $detalle->representante_legal =
                $request->input('representanteLegal');

            $detalle->representante_ci =
                $request->input('representanteCi');

            $detalle->representante_direccion =
                $request->input('representanteDireccion');

            $detalle->representante_email =
                $request->input('representanteEmail');

            $detalle->representante_cargo =
                $request->input('representanteCargo');

            $detalle->persona_contacto =
                $request->input('personaContacto')
                    ?: $displayName;

            $detalle->persona_contacto_cargo =
                $request->input('personaContactoCargo');

            $detalle->persona_contacto_telefono =
                $request->input('personaContactoTelefono');

            $detalle->persona_contacto_email =
                $request->input('personaContactoEmail')
                    ?: $email;

            $detalle->fecha_escritura_publica =
                $request->input('fechaEscrituraPublica')
                    ?: null;

            $detalle->notaria =
                $request->input('notaria');

            $detalle->plazo_pago =
                $request->input('plazoPago');

            $detalle->forma_pago =
                $request->input('formaPago');

            $detalle->dias_despacho =
                $request->input('diasDespacho');

            $detalle->banco_pago =
                $request->input('bancoPago');

            $detalle->cuenta_corriente =
                $request->input('cuentaCorriente');

            $detalle->dimension_negocio_texto =
                $request->input('dimensionNegocioTexto');

            /*
             * Se conserva el tipo de proveedor definido por el PM.
             */
            $detalle->tipo_proveedor_detalle =
                $tipoProveedorDetalle;

            $detalle->updated_at = $now;
            $detalle->save();

            /*
            |--------------------------------------------------------------------------
            | Sociedades habilitadas
            |--------------------------------------------------------------------------
            |
            | No se eliminan ni se vuelven a crear.
            | Se conservan las empresas seleccionadas previamente por el PM.
            |--------------------------------------------------------------------------
            */

            /*
            |--------------------------------------------------------------------------
            | Crear documentos del proveedor
            |--------------------------------------------------------------------------
            */

            $this->documentosService
                ->crearDocumentosProveedor(
                    $proveedor->id,
                    $tipoProveedorDetalle
                );

            /*
            |--------------------------------------------------------------------------
            | Generar ficha automática PDF
            |--------------------------------------------------------------------------
            */

            $this->fichaPdfService
                ->generarYRegistrarFicha($proveedor->id);

            /*
            |--------------------------------------------------------------------------
            | Workflow y validaciones legacy
            |--------------------------------------------------------------------------
            */

            $this->createDefaultWorkflowSteps(
                $proveedor->id
            );

            $this->createDefaultValidaciones(
                $proveedor->id
            );

            /*
            |--------------------------------------------------------------------------
            | Historial
            |--------------------------------------------------------------------------
            */

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Proveedor activado y datos completados desde invitación',
                'realizado_por' => $displayName,
                'created_at' => $now,
            ]);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Documentos del proveedor creados desde catálogo documental',
                'realizado_por' => 'Sistema',
                'created_at' => $now,
            ]);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Ficha de incorporación proveedor generada automáticamente',
                'realizado_por' => 'Sistema',
                'created_at' => $now,
            ]);

            ProveedorHistorial::create([
                'proveedor_id' => $proveedor->id,
                'accion' => 'Se conservaron las empresas definidas por el PM: ' .
                    implode(', ', $sociedadesDefinidas),
                'realizado_por' => 'Sistema',
                'created_at' => $now,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Marcar invitación como utilizada
            |--------------------------------------------------------------------------
            */

            $invitation->status = 'used';
            $invitation->used_at = $now;
            $invitation->save();

            DB::commit();
            $transactionStarted = false;

            /*
            |--------------------------------------------------------------------------
            | Crear sesión del proveedor
            |--------------------------------------------------------------------------
            */

            $session = $this->authService
                ->buildSessionForUser(
                    $user,
                    'local'
                );

            return $this->responseData([
                'message' => 'Proveedor activado correctamente',
                'session' => $session,

                'proveedor' => [
                    'id' => (string) $proveedor->id,
                    'razonSocial' =>
                        $proveedor->razon_social,
                    'rut' => $proveedor->rut,
                    'giro' => $proveedor->giro,
                    'tipo' => $proveedor->tipo,
                    'empresaId' =>
                        $proveedor->empresa_id,
                    'estado' => $proveedor->estado,

                    'detalle' => [
                        'direccion' =>
                            $request->input('direccion'),

                        'comuna' =>
                            $request->input('comuna'),

                        'region' =>
                            $request->input('region'),

                        'dimensionNegocioTexto' =>
                            $request->input(
                                'dimensionNegocioTexto'
                            ),

                        'tipoProveedorDetalle' =>
                            $tipoProveedorDetalle,
                    ],

                    'sociedadesHabilitadas' =>
                        $sociedadesDefinidas,
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->respuestaValidacion($e, isset($transactionStarted) && $transactionStarted);
        } catch (\Exception $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Genera token seguro para invitación.
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Construye el enlace de activación.
     */
    private function buildActivationLink(
        Request $request,
        string $token
    ): string {
        $frontendUrl = env('FRONTEND_URL');

        if (!$frontendUrl) {
            $frontendUrl =
                $request->getSchemeAndHttpHost();
        }

        return rtrim($frontendUrl, '/') .
            '/proveedor/activar?token=' .
            $token;
    }

    /**
     * Mapea invitación para frontend.
     */
    private function mapInvitation(
        ProveedorInvitation $invitation,
        ?string $activationLink = null
    ): array {
        return [
            'id' => (string) $invitation->id,

            'proveedorId' => $invitation->proveedor_id
                ? (string) $invitation->proveedor_id
                : null,

            'email' => $invitation->email,
            'token' => $invitation->token,
            'status' => $invitation->status,

            'createdById' =>
                $invitation->created_by_user_id
                    ? (string) $invitation->created_by_user_id
                    : null,

            'createdByEmail' => $invitation->creadoPor
                ? $invitation->creadoPor->email
                : null,

            'createdByName' => $invitation->creadoPor
                ? $invitation->creadoPor->display_name
                : null,

            'createdAt' => $invitation->created_at,
            'usedAt' => $invitation->used_at,
            'revokedAt' => $invitation->revoked_at,
            'activationLink' => $activationLink,
        ];
    }

    /**
     * Crea etapas oficiales del workflow si no existen.
     */
    private function createDefaultWorkflowSteps(
        int $proveedorId
    ): void {
        $exists = ProveedorWorkflowStep::where(
            'proveedor_id',
            $proveedorId
        )->exists();

        if ($exists) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $steps = [
            [
                'step_key' => 'pm',
                'step_name' => 'PM',
                'area' => 'pm',
                'sort_order' => 1,
                'status' => 'En revisión',
            ],
            [
                'step_key' => 'contraloria',
                'step_name' => 'Contraloría',
                'area' => 'contraloria',
                'sort_order' => 2,
                'status' => 'Pendiente',
            ],
            [
                'step_key' => 'legal_crediticia',
                'step_name' => 'Legal Crediticia',
                'area' => 'legal_crediticia',
                'sort_order' => 3,
                'status' => 'Pendiente',
            ],
            [
                'step_key' => 'legal_judicial',
                'step_name' => 'Legal Judicial',
                'area' => 'legal_judicial',
                'sort_order' => 4,
                'status' => 'Pendiente',
            ],
            [
                'step_key' => 'datos_maestros',
                'step_name' => 'Datos Maestros',
                'area' => 'datos_maestros',
                'sort_order' => 5,
                'status' => 'Pendiente',
            ],
        ];

        foreach ($steps as $step) {
            ProveedorWorkflowStep::create([
                'proveedor_id' => $proveedorId,
                'step_key' => $step['step_key'],
                'step_name' => $step['step_name'],
                'area' => $step['area'],
                'status' => $step['status'],
                'sort_order' => $step['sort_order'],
                'assigned_user_id' => null,
                'validated_by_user_id' => null,
                'validated_at' => null,
                'comments' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Crea validaciones oficiales por área si no existen.
     */
    private function createDefaultValidaciones(
        int $proveedorId
    ): void {
        $exists = ProveedorValidacion::where(
            'proveedor_id',
            $proveedorId
        )->exists();

        if ($exists) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $validaciones = [
            [
                'area' => 'pm',
                'area_name' => 'PM',
                'status' => 'En revisión',
            ],
            [
                'area' => 'contraloria',
                'area_name' => 'Contraloría',
                'status' => 'Pendiente',
            ],
            [
                'area' => 'legal_crediticia',
                'area_name' => 'Legal Crediticia',
                'status' => 'Pendiente',
            ],
            [
                'area' => 'legal_judicial',
                'area_name' => 'Legal Judicial',
                'status' => 'Pendiente',
            ],
            [
                'area' => 'datos_maestros',
                'area_name' => 'Datos Maestros',
                'status' => 'Pendiente',
            ],
        ];

        foreach ($validaciones as $validacion) {
            ProveedorValidacion::create([
                'proveedor_id' => $proveedorId,
                'area' => $validacion['area'],
                'area_name' => $validacion['area_name'],
                'status' => $validacion['status'],
                'validated_by_user_id' => null,
                'validated_at' => null,
                'comments' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}