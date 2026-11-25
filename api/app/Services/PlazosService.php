<?php

namespace App\Services;

use App\Models\Proveedores;
use App\Models\ProveedorWorkflowStep;

/*
|--------------------------------------------------------------------------
| Plazos por etapa del workflow
|--------------------------------------------------------------------------
|
| Cada etapa comienza cuando la etapa anterior queda aprobada (la primera,
| cuando se crea la solicitud) y termina cuando el área la aprueba o la
| rechaza. Si la etapa anterior no ha terminado, la etapa está "en espera"
| y no corre plazo.
|
| El plazo máximo por etapa se configura con WORKFLOW_PLAZO_DIAS (días
| corridos, 3 por defecto). Estos datos permiten medir el tiempo del
| proceso TO-BE y compararlo con el AS-IS.
|
*/
class PlazosService
{
    public function plazoDias(): int
    {
        return max(1, (int) env('WORKFLOW_PLAZO_DIAS', 3));
    }

    /**
     * Etapas de un proveedor con su inicio, fin, duración y si están vencidas.
     */
    public function etapas(Proveedores $proveedor): array
    {
        $steps = ProveedorWorkflowStep::where('proveedor_id', $proveedor->id)
            ->orderBy('sort_order')
            ->get();

        $plazo = $this->plazoDias();
        $ahora = time();
        $inicioSiguiente = strtotime((string) $proveedor->created_at) ?: $ahora;
        $resultado = [];

        foreach ($steps as $step) {
            $inicio = $inicioSiguiente;
            $terminada = in_array($step->status, ['Aprobado', 'Rechazado'], true) && $step->validated_at;
            $fin = $terminada ? strtotime((string) $step->validated_at) : null;
            $enEspera = $inicio === null;

            $dias = $enEspera
                ? null
                : round((($fin ?: $ahora) - $inicio) / 86400, 1);

            $resultado[] = [
                'stepId'    => (string) $step->id,
                'area'      => $step->area,
                'stepName'  => $step->step_name,
                'status'    => $step->status,
                'inicio'    => $enEspera ? null : date('Y-m-d H:i:s', $inicio),
                'fin'       => $fin ? date('Y-m-d H:i:s', $fin) : null,
                'dias'      => $dias,
                'plazoDias' => $plazo,
                'enEspera'  => $enEspera,
                'vencida'   => !$enEspera && !$terminada && $dias > $plazo,
            ];

            /*
             * La siguiente etapa solo comienza si esta quedó aprobada.
             */
            $inicioSiguiente = ($step->status === 'Aprobado' && $fin) ? $fin : null;
        }

        return $resultado;
    }

    /**
     * Indicadores agregados para el dashboard.
     */
    public function resumen($proveedores): array
    {
        $porArea = [];
        $vencidas = [];
        $duracionesTotales = [];

        foreach ($proveedores as $proveedor) {
            $etapas = $this->etapas($proveedor);

            foreach ($etapas as $etapa) {
                if ($etapa['fin'] && $etapa['dias'] !== null) {
                    $porArea[$etapa['area']][] = $etapa['dias'];
                }

                if ($etapa['vencida']) {
                    $vencidas[] = [
                        'proveedorId' => (string) $proveedor->id,
                        'razonSocial' => $proveedor->razon_social,
                        'area'        => $etapa['area'],
                        'stepName'    => $etapa['stepName'],
                        'dias'        => $etapa['dias'],
                    ];
                }
            }

            if ($proveedor->estado === 'Aprobado' && count($etapas) > 0) {
                $ultima = end($etapas);

                if ($ultima['fin']) {
                    $duracionesTotales[] = round(
                        (strtotime($ultima['fin']) - strtotime((string) $proveedor->created_at)) / 86400,
                        1
                    );
                }
            }
        }

        $promedioPorArea = [];

        foreach ($porArea as $area => $dias) {
            $promedioPorArea[] = [
                'area'          => $area,
                'promedioDias'  => round(array_sum($dias) / count($dias), 1),
                'etapasCerradas' => count($dias),
            ];
        }

        return [
            'plazoDias'          => $this->plazoDias(),
            'etapasVencidas'     => count($vencidas),
            'detalleVencidas'    => array_slice($vencidas, 0, 10),
            'promedioPorArea'    => $promedioPorArea,
            'promedioTotalDias'  => count($duracionesTotales)
                ? round(array_sum($duracionesTotales) / count($duracionesTotales), 1)
                : null,
            'proveedoresMedidos' => count($duracionesTotales),
        ];
    }
}
