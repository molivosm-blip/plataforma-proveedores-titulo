<?php

namespace App\Console\Commands;

use App\Models\ProveedorDocumento;
use App\Services\NotificacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/*
| Avisa los documentos aprobados que vencen pronto o que ya vencieron.
|
| - Por vencer: faltan DOCUMENTO_AVISO_DIAS (30 por defecto) o menos.
| - Vencido: la fecha ya pasó.
| Avisa al proveedor y al área responsable, como máximo una vez por semana
| por documento. Requiere proveedor_documentos.fecha_vencimiento
| (php artisan mejoras:aplicar).
|
| Uso: php artisan documentos:vencimientos
*/
class RevisarVencimientos extends Command
{
    protected $signature = 'documentos:vencimientos';

    protected $description = 'Notifica los documentos aprobados por vencer o vencidos';

    public function handle(NotificacionService $notificaciones): int
    {
        if (!Schema::hasColumn('proveedor_documentos', 'fecha_vencimiento')) {
            $this->warn('Falta la columna fecha_vencimiento. Ejecute: php artisan mejoras:aplicar');

            return 1;
        }

        $aviso = max(1, (int) env('DOCUMENTO_AVISO_DIAS', 30));
        $hoy = date('Y-m-d');
        $hasta = date('Y-m-d', time() + $aviso * 86400);
        $avisos = 0;

        $documentos = ProveedorDocumento::with('proveedor')
            ->where('estado', 'Aprobado')
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<=', $hasta)
            ->get();

        foreach ($documentos as $doc) {
            if (!$doc->proveedor) {
                continue;
            }

            $vencido = $doc->fecha_vencimiento < $hoy;
            $tipo = $vencido ? 'documento_vencido' : 'documento_por_vencer';
            $referencia = 'vencimiento:' . $doc->id;

            if ($notificaciones->yaNotificado($tipo, $referencia, 24 * 7)) {
                continue;
            }

            $titulo = ($vencido ? 'Documento vencido: ' : 'Documento por vencer: ') . $doc->nombre;
            $mensaje = 'El documento "' . $doc->nombre . '" de ' . $doc->proveedor->razon_social
                . ($vencido ? ' venció el ' : ' vence el ') . $doc->fecha_vencimiento . '.';

            $avisos += $notificaciones->notificarProveedor($doc->proveedor, $tipo, $titulo, $mensaje, $referencia);

            if (!empty($doc->area_responsable)) {
                $avisos += $notificaciones->notificarArea($doc->area_responsable, $doc->proveedor, $tipo, $titulo, $mensaje, $referencia);
            }
        }

        $this->info('Avisos enviados: ' . $avisos);

        return 0;
    }
}
