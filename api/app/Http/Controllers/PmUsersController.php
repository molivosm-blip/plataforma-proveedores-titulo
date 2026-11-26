<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Proveedores;
use App\Services\AuthService;

class PmUsersController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * PM_ADMIN / ADMIN_PORTAL
     * Crear un usuario PM_USER.
     *
     * POST /v1/admin/pm-users
     */
    public function store(Request $request)
    {
        try {
            $user = $request->auth;

            if (!in_array($user->role, ['pm_admin', 'admin_portal'])) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Solo pm_admin o admin_portal puede crear usuarios PM'
                ], 403);
            }

            $this->validate($request, [
                'email'        => 'required|email|unique:users,email',
                'password'     => 'required|string|min:8|max:72',
                'display_name' => 'required|string',
            ]);

            $pmUser = User::create([
                'email'         => trim(strtolower($request->input('email'))),
                'password_hash' => $this->authService->hashPassword($request->input('password'), $request->input('email')),
                'display_name'  => trim($request->input('display_name')),
                'role'          => 'pm_user',
                'auth_type'     => 'local',
                'area'          => 'pm',
                'active'        => 1,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            return $this->responseData([
                'message' => 'PM Usuario creado correctamente',
                'data'    => $this->mapUser($pmUser),
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
     * PM_ADMIN / ADMIN_PORTAL
     * Listar usuarios PM_USER.
     *
     * GET /v1/admin/pm-users
     */
    public function index(Request $request)
    {
        try {
            $user = $request->auth;

            if (!in_array($user->role, ['pm_admin', 'admin_portal'])) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $pmUsers = User::where('role', 'pm_user')
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($pmUser) {
                    return $this->mapUser($pmUser);
                });

            return $this->responseData([
                'data' => $pmUsers,
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
     * PM_ADMIN / ADMIN_PORTAL
     * Eliminar usuario PM_USER.
     *
     * DELETE /v1/admin/pm-users/{id}
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!in_array($user->role, ['pm_admin', 'admin_portal'])) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $pmUser = User::findOrFail($id);

            if ($pmUser->role !== 'pm_user') {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Solo se pueden eliminar usuarios PM_USER'
                ], 422);
            }

            /*
             * Validar si el PM User tiene proveedores creados/asociados.
             * Si tiene proveedores, no se elimina para no romper trazabilidad.
             */
            $proveedoresAsociados = Proveedores::where('created_by_user_id', $pmUser->id)->count();

            if ($proveedoresAsociados > 0) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No se puede eliminar este PM Usuario porque tiene proveedores asociados'
                ], 422);
            }

            $pmUser->delete();

            return $this->responseData([
                'message' => 'PM Usuario eliminado correctamente',
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
     * PM_ADMIN / ADMIN_PORTAL
     * Ver proveedores y sus asociaciones.
     *
     * GET /v1/admin/pm-users/proveedores
     */
    public function proveedores(Request $request)
    {
        try {
            $user = $request->auth;

            if (!in_array($user->role, ['pm_admin', 'admin_portal'])) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No autorizado'
                ], 403);
            }

            $proveedores = Proveedores::with(['usuarioProveedor', 'creadoPor'])
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($proveedor) {
                    return [
                        'proveedorId'      => (string) $proveedor->id,
                        'razonSocial'      => $proveedor->razon_social,
                        'rut'              => $proveedor->rut,
                        'estado'           => $proveedor->estado,
                        'tipo'             => $proveedor->tipo,
                        'empresaId'        => $proveedor->empresa_id,

                        'pmUserId'         => $proveedor->created_by_user_id
                            ? (string) $proveedor->created_by_user_id
                            : null,
                        'pmUserEmail'      => $proveedor->creadoPor
                            ? $proveedor->creadoPor->email
                            : null,
                        'pmUserNombre'     => $proveedor->creadoPor
                            ? $proveedor->creadoPor->display_name
                            : null,

                        'proveedorUserId'  => $proveedor->proveedor_user_id
                            ? (string) $proveedor->proveedor_user_id
                            : null,
                        'proveedorEmail'   => $proveedor->usuarioProveedor
                            ? $proveedor->usuarioProveedor->email
                            : null,
                        'proveedorNombre'  => $proveedor->usuarioProveedor
                            ? $proveedor->usuarioProveedor->display_name
                            : null,

                        'createdAt'        => $proveedor->created_at,
                    ];
                });

            return $this->responseData([
                'data' => $proveedores,
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
     * Mapea usuario al formato esperado por frontend.
     */
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