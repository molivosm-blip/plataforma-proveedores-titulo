<?php

namespace App\Console\Commands;

use App\Models\Proveedores;
use App\Services\NotificacionService;
use App\Services\PlazosService;
use Illuminate\Console\Command;

/*
| Envía un recordatorio al área cuando una etapa supera el plazo
| configurado (WORKFLOW_PLAZO_DIAS). Repite el aviso como máximo
| una vez al día por etapa.
|
| Uso: php artisan plazos:revisar
*/
class RevisarPlazos extends Command
{
    protected $signature = 'plazos:revisar';

    protected $description = 'Notifica a las áreas las etapas del workflow que superan el plazo';

    public function handle(PlazosService $plazos, NotificacionService $notificaciones): int
    {
        $proveedores = Proveedores::whereIn('estado', ['En revisión', 'Observado'])->get();
        $enviados = 0;

        foreach ($proveedores as $proveedor) {
            foreach ($plazos->etapas($proveedor) as $etapa) {
                if (!$etapa['vencida']) {
                    continue;
                }

                $referencia = 'plazo:' . $etapa['stepId'];

                if ($notificaciones->yaNotificado('plazo_vencido', $referencia, 24)) {
                    continue;
                }

                $enviados += $notificaciones->notificarArea(
                    $etapa['area'],
                    $proveedor,
                    'plazo_vencido',
                    'Etapa fuera de plazo: ' . $proveedor->razon_social,
                    'La etapa ' . $etapa['stepName'] . ' lleva ' . $etapa['dias'] . ' días, sobre el plazo de '
                        . $etapa['plazoDias'] . ' días.',
                    $referencia
                );
            }
        }

        $this->info('Recordatorios enviados: ' . $enviados);

        return 0;
    }
}
