<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtMiddleware
{
    public function handle($request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'error' => true,
                'message' => 'Token no proporcionado'
            ], 401);
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = JWT::decode(
                $token,
                new Key(env('JWT_SECRET'), 'HS256')
            );

            // Buscar usuario
            $user = User::find($decoded->sub);

            if (!$user) {
                return response()->json([
                    'error' => true,
                    'message' => 'Usuario no existe'
                ], 401);
            }

            // ✅ Inyectamos usuario autenticado en el request
            $request->auth = $user;

        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => 'Token inválido o expirado',
            ], 401);
        }

        return $next($request);
    }
}