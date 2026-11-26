<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Laravel\Lumen\Routing\Controller as BaseController;

class Controller extends BaseController{

    /**
     * Respuesta 422 para datos de entrada inválidos. Si el propio controlador
     * abrió una transacción ($transaccionPropia), la revierte para no dejar
     * nada a medias; una transacción externa (por ejemplo la de una prueba) no se toca.
     */
    protected function respuestaValidacion(\Illuminate\Validation\ValidationException $e, bool $transaccionPropia = false)
    {
        if ($transaccionPropia && \Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::rollBack();
        }

        return new JsonResponse([
            'error' => true,
            'message' => $e->getMessage(),
            'errors' => $e->errors(),
        ], 422);
    }

    protected function responseData($data,$code){
        /*
         * Con APP_DEBUG apagado, un error 500 no debe mostrar detalles técnicos
         * (consultas SQL, rutas de archivos, trazas). Los mensajes pensados
         * para el usuario no se tocan; el detalle queda en el registro.
         */
        if (
            $code === 500
            && is_array($data)
            && isset($data['message'])
            && is_string($data['message'])
            && !filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)
            && preg_match('/SQLSTATE|\bSQL:|\.php|Stack trace|vendor[\/\\\\]|Call to |Undefined /i', $data['message'])
        ) {
            error_log('Error interno ocultado al usuario: ' . $data['message']);
            $data['message'] = 'Ocurrió un error interno. Intenta nuevamente o contacta al administrador.';
        }

        return new JsonResponse($data, $code);
    }
}
