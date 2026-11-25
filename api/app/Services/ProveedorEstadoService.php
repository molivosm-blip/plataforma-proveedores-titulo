<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProveedorEstadoService
{
    /**
     * Calcula el estado general del proveedor. Es la ÚNICA regla del sistema:
     * todos los puntos que cambian algo que lo afecta (documentos, validaciones
     * por área, etapas del workflow) llaman a recalcularEstadoProveedor().
     *
     * Fuentes y prioridad:
     * 1. Rechazado: alguna validación o etapa de área está rechazada.
     * 2. Observado: alguna validación o etapa está observada, o algún
     *    documento visible para los internos está observado.
     * 3. Aprobado: todas las validaciones (y etapas) de las áreas están
     *    aprobadas. Los documentos solo pueden observar, no aprobar: cada
     *    área aprueba su etapa después de revisar sus documentos.
     * 4. En revisión: cualquier otro caso.
     *
     * Compatibilidad: un proveedor sin validaciones ni etapas (datos antiguos)
     * se calcula solo por documentos, como antes.
     */
    public function calcularEstado($proveedorId): string
    {
        $validaciones = DB::table('proveedor_validaciones')
            ->where('proveedor_id', $proveedorId)
            ->pluck('status')
            ->all();

        $etapas = DB::table('proveedor_workflow_steps')
            ->where('proveedor_id', $proveedorId)
            ->pluck('status')
            ->all();

        $documentos = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('visible_para_internos', 1)
            ->pluck('estado')
            ->all();

        return $this->resolverEstado($validaciones, $etapas, $documentos);
    }

    /**
     * Regla pura (sin base de datos), separada para poder probarla.
     */
    public function resolverEstado(array $validaciones, array $etapas, array $documentos): string
    {
        $areas = array_merge($validaciones, $etapas);

        if (in_array('Rechazado', $areas, true)) {
            return 'Rechazado';
        }

        if (in_array('Observado', $areas, true) || in_array('Observado', $documentos, true)) {
            return 'Observado';
        }

        if (count($areas) === 0) {
            // Datos antiguos sin validaciones: se calcula solo por documentos.
            if (count($documentos) > 0 && count(array_diff($documentos, ['Aprobado'])) === 0) {
                return 'Aprobado';
            }

            return 'En revisión';
        }

        if (count(array_diff($areas, ['Aprobado'])) === 0) {
            return 'Aprobado';
        }

        return 'En revisión';
    }

    /**
     * Recalcula y guarda el estado general del proveedor.
     */
    public function recalcularEstadoProveedor($proveedorId): string
    {
        $estado = $this->calcularEstado($proveedorId);

        DB::table('proveedores')
            ->where('id', $proveedorId)
            ->update([
                'estado' => $estado,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $estado;
    }

    /**
     * Verifica si el proveedor tiene todos los documentos visibles internos aprobados.
     */
    public function proveedorTieneDocumentosAprobados($proveedorId): bool
    {
        $documentos = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('visible_para_internos', 1)
            ->get();

        if ($documentos->count() === 0) {
            return false;
        }

        return $documentos->every(function ($doc) {
            return $doc->estado === 'Aprobado';
        });
    }

    /**
     * Obtiene resumen documental para frontend o auditoría.
     */
    public function obtenerResumenDocumental($proveedorId): array
    {
        $documentos = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('visible_para_internos', 1)
            ->get();

        return [
            'total' => $documentos->count(),
            'pendientes' => $documentos->where('estado', 'Pendiente')->count(),
            'subidos' => $documentos->where('estado', 'Subido')->count(),
            'observados' => $documentos->where('estado', 'Observado')->count(),
            'aprobados' => $documentos->where('estado', 'Aprobado')->count(),
        ];
    }
}