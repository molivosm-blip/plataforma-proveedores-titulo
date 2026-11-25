<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Exceptions\LoginBloqueadoException;
use App\Services\AuthService;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Login local.
     *
     * Uso principal:
     * - Proveedor
     * - Correo + contraseña
     *
     * Endpoint:
     * POST /v1/auth/login
     */
    public function login(Request $request)
    {
        try {
            $this->validate($request, [
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            $credentials = $request->only(['email', 'password']);

            $response = $this->authService->login($credentials);

            return $this->responseData($response, 200);

        } catch (LoginBloqueadoException $e) {
            return $this->responseData([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 429);
        } catch (\Exception $e) {
            return $this->responseData([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Login Microsoft / Entra ID.
     *
     * Uso principal:
     * - pm_admin
     * - pm_user
     *
     * El frontend autentica con Microsoft y luego envía el email.
     * El backend valida que el usuario exista en Users y entrega JWT propio.
     *
     * Endpoint:
     * POST /v1/auth/msal
     */
    public function msalLogin(Request $request)
    {
        try {
            $this->validate($request, [
                'email'       => 'required|email',
                'displayName' => 'nullable|string',
            ]);

            $payload = [
                'email'       => $request->input('email'),
                'displayName' => $request->input('displayName'),
            ];

            $response = $this->authService->loginWithMsal($payload);

            return $this->responseData($response, 200);

        } catch (\Exception $e) {
            return $this->responseData([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 401);
        }
    }
}