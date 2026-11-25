<?php

namespace App\Console\Commands;

use App\Services\ProveedorEstadoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
| Compara el estado guardado de cada proveedor con el que entrega la regla
| única (ProveedorEstadoService). Por defecto solo informa las diferencias;
| con --aplicar las corrige y deja una línea en el historial del proveedor.
|
| Uso:
|   php artisan estados:reconciliar            (solo informa)
|   php artisan estados:reconciliar --aplicar  (corrige)
*/
class ReconciliarEstados extends Command
{
    protected $signature = 'estados:reconciliar {--aplicar : Corrige las diferencias en la base}';

    protected $description = 'Revisa que el estado de cada proveedor coincida con la regla única de cálculo';

    public function handle(ProveedorEstadoService $estados): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $diferencias = 0;

        foreach (DB::table('proveedores')->select('id', 'razon_social', 'estado')->orderBy('id')->get() as $p) {
            $calculado = $estados->calcularEstado($p->id);

            if ($calculado === $p->estado) {
                continue;
            }

            $diferencias++;
            $this->line('#' . $p->id . ' ' . $p->razon_social . ': guardado "' . $p->estado . '", calculado "' . $calculado . '"');

            if ($aplicar) {
                $estados->recalcularEstadoProveedor($p->id);

                DB::table('proveedor_historial')->insert([
                    'proveedor_id'  => $p->id,
                    'accion'        => 'Estado corregido por reconciliación: de ' . $p->estado . ' a ' . $calculado,
                    'realizado_por' => 'Sistema',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        }

        if ($diferencias === 0) {
            $this->info('Todos los estados coinciden con la regla única.');
        } elseif ($aplicar) {
            $this->info('Estados corregidos: ' . $diferencias);
        } else {
            $this->warn('Diferencias encontradas: ' . $diferencias . '. Use --aplicar para corregirlas.');
        }

        return 0;
    }
}
