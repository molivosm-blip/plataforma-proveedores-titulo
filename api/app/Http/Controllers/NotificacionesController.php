<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use Illuminate\Http\Request;

class NotificacionesController extends Controller
{
    /**
     * Últimas notificaciones del usuario autenticado.
     *
     * GET /v1/notificaciones
     */
    public function index(Request $request)
    {
        try {
            $user = $request->auth;

            $items = Notificacion::where('user_id', $user->id)
                ->orderBy('id', 'desc')
                ->limit(30)
                ->get()
                ->map(function ($n) {
                    return [
                        'id'          => (string) $n->id,
                        'proveedorId' => $n->proveedor_id ? (string) $n->proveedor_id : null,
                        'tipo'        => $n->tipo,
                        'titulo'      => $n->titulo,
                        'mensaje'     => $n->mensaje,
                        'emailEnviado' => (bool) $n->email_enviado,
                        'leida'       => $n->leida_at !== null,
                        'createdAt'   => $n->created_at,
                    ];
                })
                ->values();

            $noLeidas = Notificacion::where('user_id', $user->id)
                ->whereNull('leida_at')
                ->count();

            return $this->responseData([
                'data' => [
                    'items'    => $items,
                    'noLeidas' => $noLeidas,
                ],
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /v1/notificaciones/{id}/leer
     */
    public function leer(Request $request, $id)
    {
        try {
            $notificacion = Notificacion::where('user_id', $request->auth->id)
                ->where('id', $id)
                ->firstOrFail();

            if (!$notificacion->leida_at) {
                $notificacion->leida_at = date('Y-m-d H:i:s');
                $notificacion->save();
            }

            return $this->responseData(['message' => 'Notificación leída'], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /v1/notificaciones/leer-todas
     */
    public function leerTodas(Request $request)
    {
        try {
            Notificacion::where('user_id', $request->auth->id)
                ->whereNull('leida_at')
                ->update(['leida_at' => date('Y-m-d H:i:s')]);

            return $this->responseData(['message' => 'Notificaciones leídas'], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }
}
