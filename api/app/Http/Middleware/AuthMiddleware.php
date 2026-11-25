<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware {

    public function handle($request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'msg' => 'Token requerido'
            ], 401);
        }

        try {
            $decoded = JWT::decode(
                $token,
                new Key(env('JWT_SECRET'), 'HS256')
            );

            // Guardamos el usuario decodificado en el request
            $request->auth = $decoded;

        } catch (\Exception $e) {
            return response()->json([
                'msg' => 'Token inválido'
            ], 401);
        }

        return $next($request);
    }
}