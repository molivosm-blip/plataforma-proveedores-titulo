<?php

namespace App\Http\Controllers;

use App\Services\RecuperacionPasswordService;
use Illuminate\Http\Request;

class RecuperacionController extends Controller
{
    protected $recuperacion;

    public function __construct(RecuperacionPasswordService $recuperacion)
    {
        $this->recuperacion = $recuperacion;
    }

    /**
     * POST /v1/auth/olvide-password
     * Responde siempre lo mismo para no revelar si el correo existe.
     */
    public function solicitar(Request $request)
    {
        $this->validate($request, ['email' => 'required|email']);

        try {
            $this->recuperacion->solicitar((string) $request->input('email'));
        } catch (\Throwable $e) {
            error_log('Recuperación de clave: ' . $e->getMessage());
        }

        return $this->responseData([
            'message' => 'Si el correo corresponde a una cuenta de proveedor, enviamos un enlace para cambiar la clave.',
        ], 200);
    }

    /**
     * POST /v1/auth/restablecer-password
     */
    public function restablecer(Request $request)
    {
        $this->validate($request, [
            'token'    => 'required|string|size:64',
            'password' => 'required|string|max:72',
        ]);

        try {
            $this->recuperacion->restablecer(
                (string) $request->input('token'),
                (string) $request->input('password')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 422);
        }

        return $this->responseData(['message' => 'Tu clave fue actualizada. Ya puedes ingresar.'], 200);
    }
}
