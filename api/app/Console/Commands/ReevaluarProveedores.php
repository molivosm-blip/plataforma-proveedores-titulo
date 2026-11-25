<?php

namespace App\Console\Commands;

use App\Services\EvaluacionCrediticiaService;
use Illuminate\Console\Command;

/*
| Re-evaluación periódica de los proveedores aprobados.
|
| Repite las reglas crediticias de los proveedores cuya última evaluación
| tiene más de REEVALUACION_MESES (12 por defecto). La re-evaluación
| revisa además que las consultas DICOM y Gesintel sigan vigentes y avisa
| a Legal Crediticia si el resultado empeora.
|
| Uso: php artisan proveedores:reevaluar [--meses=12]
*/
class ReevaluarProveedores extends Command
{
    protected $signature = 'proveedores:reevaluar {--meses= : Antigüedad mínima de la última evaluación}';

    protected $description = 'Re-evalúa periódicamente a los proveedores aprobados';

    public function handle(EvaluacionCrediticiaService $evaluaciones): int
    {
        $meses = (int) ($this->option('meses') ?: env('REEVALUACION_MESES', 12));

        $proveedores = $evaluaciones->proveedoresParaReevaluar(max(0, $meses));

        foreach ($proveedores as $proveedor) {
            $evaluacion = $evaluaciones->reevaluar($proveedor);

            $this->line($proveedor->razon_social . ': ' . strtoupper($evaluacion->resultado));
        }

        $this->info('Proveedores re-evaluados: ' . $proveedores->count());

        return 0;
    }
}
