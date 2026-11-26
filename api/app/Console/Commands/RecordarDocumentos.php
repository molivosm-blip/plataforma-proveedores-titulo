<?php

namespace App\Console\Commands;

use App\Models\ProveedorDocumento;
use App\Models\Proveedores;
use App\Services\NotificacionService;
use Illuminate\Console\Command;

/*
| Recuerda al proveedor los documentos que tiene pendientes u observados.
|
| - Solo proveedores con proceso abierto (En revisión u Observado).
| - Solo documentos que debe subir el propio proveedor.
| - Espera RECORDATORIO_DOCUMENTOS_DIAS (3 por defecto) desde la última
|   actualización del documento y repite como máximo cada 72 horas.
|
| Uso: php artisan proveedores:recordar
*/
class RecordarDocumentos extends Command
{
    protected $signature = 'proveedores:recordar';

    protected $description = 'Recuerda a los proveedores sus documentos pendientes u observados';

    public function handle(NotificacionService $notificaciones): int
    {
        $dias = max(1, (int) env('RECORDATORIO_DOCUMENTOS_DIAS', 3));
        $limite = date('Y-m-d H:i:s', time() - $dias * 86400);
        $enviados = 0;

        $proveedores = Proveedores::whereIn('estado', ['En revisión', 'Observado'])
            ->whereNotNull('proveedor_user_id')
            ->get();

        foreach ($proveedores as $proveedor) {
            $pendientes = ProveedorDocumento::where('proveedor_id', $proveedor->id)
                ->where('responsable_carga', 'proveedor')
                ->where('visible_para_proveedor', 1)
                ->whereIn('estado', ['Pendiente', 'Observado'])
                ->where(function ($q) use ($limite) {
                    $q->where('updated_at', '<=', $limite)->orWhereNull('updated_at');
                })
                ->get();

            if ($pendientes->isEmpty()) {
                continue;
            }

            $referencia = 'recordatorio:' . $proveedor->id;

            if ($notificaciones->yaNotificado('recordatorio_documentos', $referencia, 72)) {
                continue;
            }

            $nombres = $pendientes->pluck('nombre')->take(5)->implode(', ');
            $extra = $pendientes->count() > 5 ? ' y ' . ($pendientes->count() - 5) . ' más' : '';

            $enviados += $notificaciones->notificarProveedor(
                $proveedor,
                'recordatorio_documentos',
                'Tienes documentos pendientes',
                'Para continuar con tu solicitud necesitamos: ' . $nombres . $extra . '.',
                $referencia
            );
        }

        $this->info('Recordatorios enviados: ' . $enviados);

        return 0;
    }
}
