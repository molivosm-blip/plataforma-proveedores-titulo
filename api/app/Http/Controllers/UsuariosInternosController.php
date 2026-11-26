<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Area;
use App\Services\AuthService;
use App\Services\AreaPermissionService;

class UsuariosInternosController extends Controller
{
    protected $permissions;
    protected $authService;

    public function __construct(
        AreaPermissionService $permissions,
        AuthService $authService
    ) {
        $this->permissions = $permissions;
        $this->authService = $authService;
    }

    /**
     * Listar usuarios internos.
     *
     * GET /v1/admin/usuarios
     */
    public function index(Request $request)
    {
        try {
            $authUser = $request->auth;

            if (!$this->permissions->canManageInternalUsers($authUser)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado para administrar usuarios'
                ], 403);
            }

            $query = User::whereIn('role', [
                'pm_admin',
                'pm_user',
                'admin_portal',
                'admin_area',
                'usuario_area',
                'auditor'
            ])->orderBy('id', 'desc');

            /*
             * admin_area solo ve su área.
             */
            if ($this->permissions->isAreaAdmin($authUser)) {
                $query->where('area', $authUser->area);
            }

            $usuarios = $query->get()->map(function ($user) {
                return $this->mapUser($user);
            });

            return $this->responseData([
                'data' => $usuarios,
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
     * Crear usuario interno.
     *
     * POST /v1/admin/usuarios
     */
    public function store(Request $request)
    {
        try {
            $authUser = $request->auth;

            if (!$this->permissions->canManageInternalUsers($authUser)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado para crear usuarios'
                ], 403);
            }

            $this->validate($request, [
                'email'       => 'required|email|unique:users,email',
                'password'    => 'required|string|min:8|max:72',
                'displayName' => 'required|string',
                'role'        => 'required|string',
                'area'        => 'nullable|string',
            ]);

            $role = $request->input('role');
            $area = $request->input('area');

            $rolesPermitidos = [
                'admin_portal',
                'admin_area',
                'usuario_area',
                'pm_admin',
                'pm_user',
                'auditor',
            ];

            if (!in_array($role, $rolesPermitidos, true)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Rol no válido'
                ], 422);
            }

            /*
             * Compatibilidad:
             * pm_user se considera usuario del área PM.
             */
            if ($role === 'pm_user' && !$area) {
                $area = 'pm';
            }

            /*
             * Roles por área requieren área.
             */
            if (in_array($role, ['admin_area', 'usuario_area'], true) && !$area) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'El área es obligatoria para este rol'
                ], 422);
            }

            /*
             * Validar área.
             */
            if ($area) {
                $areaExiste = Area::where('code', $area)
                    ->where('active', 1)
                    ->first();

                if (!$areaExiste) {
                    return $this->responseData([
                        'error' => true,
                        'message' => 'Área no válida'
                    ], 422);
                }
            }

            /*
             * Validar permisos usando servicio.
             */
            if (!$this->permissions->canCreateUserInArea($authUser, $role, $area)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No tienes permisos para crear este usuario'
                ], 403);
            }

            $user = User::create([
                'email'         => trim(strtolower($request->input('email'))),
                'password_hash' => $this->authService->hashPassword($request->input('password'), $request->input('email')),
                'display_name'  => trim($request->input('displayName')),
                'role'          => $role,
                'area'          => $area,
                'active'        => 1,
                'auth_type'     => 'entra',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            return $this->responseData([
                'message' => 'Usuario interno creado correctamente',
                'data'    => $this->mapUser($user),
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
     * Desactivar usuario interno.
     *
     * DELETE /v1/admin/usuarios/{id}
     */
    public function destroy(Request $request, $id)
    {
        try {
            $authUser = $request->auth;

            if (!$this->permissions->canManageInternalUsers($authUser)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado para eliminar usuarios'
                ], 403);
            }

            $user = User::findOrFail($id);

            if (!$this->permissions->canDeactivateUser($authUser, $user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No tienes permisos para desactivar este usuario'
                ], 403);
            }

            /*
             * Soft delete lógico.
             */
            $user->active = 0;
            $user->save();

            return $this->responseData([
                'message' => 'Usuario desactivado correctamente',
                'data'    => $this->mapUser($user),
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
     * Listar áreas.
     *
     * GET /v1/admin/areas
     */
    public function areas(Request $request)
    {
        try {
            $areas = Area::where('active', 1)
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($area) {
                    return [
                        'id'     => (string) $area->id,
                        'code'   => $area->code,
                        'name'   => $area->name,
                        'active' => (int) $area->active,
                    ];
                });

            return $this->responseData([
                'data' => $areas,
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

    private function mapUser(User $user): array
    {
        return [
            'id'           => (string) $user->id,
            'email'        => $user->email,
            'display_name' => $user->display_name,
            'displayName'  => $user->display_name,
            'role'         => $user->role,
            'area'         => $user->area,
            'active'       => (int) $user->active,
            'auth_type'    => $user->auth_type,
            'authType'     => $user->auth_type,
            'createdAt'    => $user->created_at,
        ];
    }
}