<?php

namespace App\Http\Controllers;

use App\Models\Proveedores;
use App\Services\AreaPermissionService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Bitácora y exportación de proveedores (usuarios internos)
|--------------------------------------------------------------------------
|
|  GET /v1/interno/proveedores/{id}/bitacora   Historial de acciones.
|  GET /v1/interno/proveedores/exportar        Listado en CSV (abre en Excel).
|
| Ambos respetan la misma visibilidad que el listado normal: cada área
| ve solo los proveedores de su área.
|
*/
class ProveedorExtrasController extends Controller
{
    protected $permissions;

    public function __construct(AreaPermissionService $permissions)
    {
        $this->permissions = $permissions;
    }

    public function bitacora(Request $request, $id)
    {
        $user = $request->auth;

        if (!$this->permissions->isInternalUser($user)) {
            return $this->responseData(['error' => true, 'message' => 'No autorizado'], 403);
        }

        $query = Proveedores::with('historial')->where('id', $id);
        $this->permissions->applyProveedorVisibilityQuery($query, $user);
        $proveedor = $query->first();

        if (!$proveedor) {
            return $this->responseData(['error' => true, 'message' => 'Proveedor no encontrado'], 404);
        }

        $eventos = $proveedor->historial
            ->sortByDesc('id')
            ->map(function ($h) {
                return [
                    'id'           => (string) $h->id,
                    'accion'       => $h->accion,
                    'realizadoPor' => $h->realizado_por,
                    'fecha'        => $h->created_at,
                ];
            })
            ->values();

        return $this->responseData([
            'data' => [
                'proveedorId' => (string) $proveedor->id,
                'razonSocial' => $proveedor->razon_social,
                'total'       => $eventos->count(),
                'eventos'     => $eventos,
            ],
        ], 200);
    }

    public function exportar(Request $request)
    {
        $user = $request->auth;

        if (!$this->permissions->isInternalUser($user)) {
            return $this->responseData(['error' => true, 'message' => 'No autorizado'], 403);
        }

        $query = Proveedores::with(['creadoPor', 'usuarioProveedor', 'documentos', 'workflowSteps'])
            ->orderBy('id', 'desc');

        $this->permissions->applyProveedorVisibilityQuery($query, $user);

        if ($request->filled('estado')) {
            $query->where('estado', $request->input('estado'));
        }

        if ($request->filled('empresaId')) {
            $query->where('empresa_id', $request->input('empresaId'));
        }

        $columnas = [
            'ID', 'Razón social', 'RUT', 'Tipo', 'Empresa', 'Estado',
            'Documentos aprobados', 'Documentos totales',
            'Etapas aprobadas', 'Etapas totales',
            'Solicitante', 'Correo del proveedor', 'Creado', 'Actualizado',
        ];

        $filas = [$columnas];

        foreach ($query->get() as $p) {
            $docs = $p->documentos;
            $etapas = $p->workflowSteps;

            $filas[] = [
                $p->id,
                $p->razon_social,
                $p->rut,
                $p->tipo,
                $p->empresa_id,
                $p->estado,
                $docs->where('estado', 'Aprobado')->count(),
                $docs->count(),
                $etapas->where('status', 'Aprobado')->count(),
                $etapas->count(),
                $p->creadoPor ? ($p->creadoPor->display_name ?: $p->creadoPor->email) : '',
                $p->usuarioProveedor ? $p->usuarioProveedor->email : '',
                $p->created_at,
                $p->updated_at,
            ];
        }

        // Separador ";" y BOM UTF-8: así Excel en español abre las tildes y las columnas bien.
        $csv = "\xEF\xBB\xBF";
        foreach ($filas as $fila) {
            $csv .= implode(';', array_map([$this, 'celda'], $fila)) . "\r\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="proveedores_' . date('Ymd_His') . '.csv"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * Escapa una celda CSV. Los valores que empiezan con = + - @ se
     * neutralizan para que Excel no los ejecute como fórmula.
     */
    private function celda($valor): string
    {
        $texto = (string) $valor;

        if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $texto = "'" . $texto;
        }

        return '"' . str_replace('"', '""', $texto) . '"';
    }
}
