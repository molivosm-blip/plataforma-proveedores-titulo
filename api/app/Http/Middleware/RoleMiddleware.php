<?php

namespace App\Http\Middleware;

use Closure;

class RoleMiddleware
{
    public function handle($request, Closure $next, ...$roles)
    {
        // El usuario ya viene desde el JwtMiddleware
        if (!isset($request->auth)) {
            return response()->json([
                'error' => true,
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $userRole = $request->auth->role;

        // Validar si el rol del usuario está permitido
        if (!in_array($userRole, $roles)) {
            return response()->json([
                'error' => true,
                'message' => 'No autorizado para este recurso'
            ], 403);
        }

        return $next($request);
    }
}