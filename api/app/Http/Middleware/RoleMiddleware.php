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

        // El auditor es de solo lectura: cualquier método que no sea de consulta se rechaza.
        if ($userRole === 'auditor' && !in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json([
                'error' => true,
                'message' => 'El rol auditor es de solo lectura'
            ], 403);
        }

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