<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Generación de PDF y registro como documento del proveedor
|--------------------------------------------------------------------------
|
| Lo usan las declaraciones digitales y el informe de evaluación crediticia.
| El PDF queda en storage/app/uploads/{carpeta} (privado) y se asocia al documento del
| proveedor con estado "Subido", para que el área responsable lo revise
| en la misma bandeja que el resto de los documentos.
|
*/
class PdfDocumentoService
{
    public function renderizar(string $html, string $carpeta, string $prefijo, $proveedorId): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $fileName = $prefijo . '_' . $proveedorId . '_' . time() . '.pdf';

        return app(DocumentoStorageService::class)
            ->guardarContenido($carpeta, $fileName, $dompdf->output());
    }

    /**
     * Crea o actualiza el documento del proveedor con el PDF generado.
     * Devuelve el id del documento.
     */
    public function registrarDocumento(
        $proveedorId,
        string $nombre,
        string $archivoUrl,
        array $valoresPorDefecto,
        string $comentario
    ): int {
        $now = date('Y-m-d H:i:s');

        $documento = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('nombre', $nombre)
            ->first();

        $archivo = [
            'estado'            => 'Subido',
            'archivo_nombre'    => $nombre . '.pdf',
            'archivo_url'       => $archivoUrl,
            'archivo_mime_type' => 'application/pdf',
            'comentario'        => $comentario,
            'reviewed_by_user_id' => null,
            'reviewed_at'       => null,
            'updated_at'        => $now,
        ];

        if ($documento) {
            DB::table('proveedor_documentos')
                ->where('id', $documento->id)
                ->update($archivo);

            return (int) $documento->id;
        }

        return (int) DB::table('proveedor_documentos')->insertGetId(array_merge(
            [
                'proveedor_id'            => $proveedorId,
                'nombre'                  => $nombre,
                'responsable_carga'       => 'interno',
                'area_responsable'        => null,
                'es_prerrequisito'        => 0,
                'bloquea_carga_proveedor' => 0,
                'visible_para_proveedor'  => 1,
                'visible_para_internos'   => 1,
                'plantilla_url'           => null,
                'created_at'              => $now,
            ],
            $valoresPorDefecto,
            $archivo
        ));
    }

    public function h($value): string
    {
        $texto = trim((string) ($value ?? ''));

        return htmlspecialchars($texto === '' ? '—' : $texto, ENT_QUOTES, 'UTF-8');
    }

    public function fila(string $label, $value): string
    {
        return '<tr><td class="label">' . $this->h($label) . '</td><td>' . $this->h($value) . '</td></tr>';
    }

    /**
     * Tabla con encabezados. $filas es una lista de arreglos con las celdas.
     */
    public function tabla(array $encabezados, array $filas): string
    {
        if (count($filas) === 0) {
            return '<p class="muted">Sin registros.</p>';
        }

        $html = '<table><tr>';

        foreach ($encabezados as $encabezado) {
            $html .= '<th>' . $this->h($encabezado) . '</th>';
        }

        $html .= '</tr>';

        foreach ($filas as $fila) {
            $html .= '<tr>';

            foreach ($fila as $celda) {
                $html .= '<td>' . $this->h($celda) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    public function documentoHtml(string $titulo, string $subtitulo, string $cuerpo): string
    {
        return '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 24px; }
    .header { border-bottom: 2px solid #4f46e5; padding-bottom: 12px; margin-bottom: 18px; }
    .title { font-size: 20px; font-weight: bold; color: #111827; margin: 0; }
    .subtitle { font-size: 11px; color: #6b7280; margin-top: 5px; }
    .section { margin-top: 18px; }
    .section-title { background: #eef2ff; color: #3730a3; padding: 7px 9px; font-weight: bold; border-radius: 4px; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th { background: #f3f4f6; text-align: left; border: 1px solid #e5e7eb; padding: 6px 7px; }
    td { border: 1px solid #e5e7eb; padding: 6px 7px; vertical-align: top; }
    .label { width: 34%; background: #f9fafb; font-weight: bold; color: #374151; }
    .muted { color: #6b7280; }
    .firma { margin-top: 14px; padding: 10px; border: 1px solid #c7d2fe; background: #f5f7ff; }
    .hash { font-family: DejaVu Sans Mono, monospace; font-size: 9px; word-break: break-all; }
    .semaforo { display: inline-block; padding: 4px 10px; border-radius: 10px; color: #fff; font-weight: bold; }
    .verde { background: #15803d; } .amarillo { background: #b45309; } .rojo { background: #b91c1c; }
    .footer { margin-top: 24px; font-size: 9px; color: #6b7280; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 8px; }
</style>
</head>
<body>
    <div class="header">
        <p class="title">' . $this->h($titulo) . '</p>
        <p class="subtitle">' . $this->h($subtitulo) . '</p>
    </div>
    ' . $cuerpo . '
    <div class="footer">Documento generado automáticamente por el Portal de Proveedores.</div>
</body>
</html>';
    }
}
