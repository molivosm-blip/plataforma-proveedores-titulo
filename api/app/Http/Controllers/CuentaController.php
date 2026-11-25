<?php

namespace App\Http\Controllers;

use App\Exceptions\LoginBloqueadoException;
use App\Services\AuthService;
use App\Services\CuentaService;
use Illuminate\Http\Request;

/*
| Página "Mi cuenta" del portal. Todas las rutas actúan solo sobre el usuario
| autenticado: no reciben un id de usuario.
*/
class CuentaController extends Controller
{
    protected $cuenta;
    protected $auth;

    public function __construct(CuentaService $cuenta, AuthService $auth)
    {
        $this->cuenta = $cuenta;
        $this->auth = $auth;
    }

    /** GET /v1/cuenta */
    public function perfil(Request $request)
    {
        return $this->responseData(['data' => $this->cuenta->perfil($request->auth)], 200);
    }

    /** PUT /v1/cuenta/password (solo proveedores) */
    public function cambiarPassword(Request $request)
    {
        $user = $request->auth;

        if ($user->role !== 'proveedor') {
            return $this->responseData([
                'error'   => true,
                'message' => 'Los usuarios internos inician sesión con Microsoft; su contraseña se administra en la cuenta Microsoft.',
            ], 403);
        }

        $actual = $request->input('passwordActual');
        $nueva = $request->input('passwordNueva');

        if (!is_string($actual) || $actual === '' || !is_string($nueva) || $nueva === '') {
            return $this->responseData(['error' => true, 'message' => 'Ingrese la contraseña actual y la nueva.'], 422);
        }

        try {
            $this->auth->cambiarPassword($user, $actual, $nueva);
        } catch (LoginBloqueadoException $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 429);
        } catch (\InvalidArgumentException $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('Cambio de contraseña: ' . $e->getMessage());

            return $this->responseData(['error' => true, 'message' => 'No fue posible cambiar la contraseña.'], 500);
        }

        return $this->responseData(['message' => 'Contraseña actualizada.'], 200);
    }

    /** GET /v1/cuenta/notificaciones */
    public function preferencias(Request $request)
    {
        return $this->responseData([
            'data' => [
                'items'      => $this->cuenta->preferencias($request->auth),
                'disponible' => $this->cuenta->preferenciasDisponibles(),
            ],
        ], 200);
    }

    /** PUT /v1/cuenta/notificaciones  { "estado": true, "documentos": false, ... } */
    public function guardarPreferencias(Request $request)
    {
        $valores = $request->input('preferencias');

        if (!is_array($valores)) {
            return $this->responseData(['error' => true, 'message' => 'Faltan las preferencias.'], 422);
        }

        try {
            $items = $this->cuenta->guardarPreferencias($request->auth, $valores);
        } catch (\InvalidArgumentException $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 422);
        }

        return $this->responseData(['data' => ['items' => $items, 'disponible' => true]], 200);
    }
}
