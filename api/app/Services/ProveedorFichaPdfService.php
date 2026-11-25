<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;

class ProveedorFichaPdfService
{
    public function generarYRegistrarFicha($proveedorId): string
    {
        $proveedor = DB::table('proveedores as p')
            ->leftJoin('empresas as e', 'e.id', '=', 'p.empresa_id')
            ->leftJoin('proveedor_detalles as d', 'd.proveedor_id', '=', 'p.id')
            ->where('p.id', $proveedorId)
            ->select(
                'p.*',
                'e.nombre as empresa_nombre',
                'd.direccion',
                'd.comuna',
                'd.region',
                'd.codigo_postal',
                'd.telefono',
                'd.moneda',
                'd.pais',
                'd.representante_legal',
                'd.representante_ci',
                'd.representante_direccion',
                'd.representante_email',
                'd.representante_cargo',
                'd.persona_contacto',
                'd.persona_contacto_cargo',
                'd.persona_contacto_telefono',
                'd.persona_contacto_email',
                'd.fecha_escritura_publica',
                'd.notaria',
                'd.plazo_pago',
                'd.forma_pago',
                'd.dias_despacho',
                'd.banco_pago',
                'd.cuenta_corriente',
                'd.dimension_negocio_texto',
                'd.tipo_proveedor_detalle'
            )
            ->first();

        if (!$proveedor) {
            throw new \Exception('Proveedor no encontrado para generar ficha PDF.');
        }

        $sociedades = DB::table('proveedor_sociedades_habilitadas')
            ->where('proveedor_id', $proveedorId)
            ->orderBy('sociedad_nombre')
            ->pluck('sociedad_nombre')
            ->toArray();

        $html = $this->buildHtml($proveedor, $sociedades);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $fileName = 'ficha_proveedor_' . $proveedorId . '_' . time() . '.pdf';

        $archivoUrl = app(DocumentoStorageService::class)
            ->guardarContenido('proveedor_fichas', $fileName, $dompdf->output());

        $documento = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('nombre', 'Ficha de incorporación proveedor')
            ->first();

        if ($documento) {
            DB::table('proveedor_documentos')
                ->where('id', $documento->id)
                ->update([
                    'responsable_carga' => 'interno',
                    'area_responsable' => 'datos_maestros',
                    'es_prerrequisito' => 0,
                    'bloquea_carga_proveedor' => 0,
                    'visible_para_proveedor' => 1,
                    'visible_para_internos' => 1,
                    'plantilla_url' => null,
                    'estado' => 'Subido',
                    'archivo_nombre' => 'Ficha de incorporación proveedor.pdf',
                    'archivo_url' => $archivoUrl,
                    'archivo_mime_type' => 'application/pdf',
                    'comentario' => 'Documento generado automáticamente desde los datos de incorporación.',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            DB::table('proveedor_documentos')->insert([
                'proveedor_id' => $proveedorId,
                'nombre' => 'Ficha de incorporación proveedor',
                'responsable_carga' => 'interno',
                'area_responsable' => 'datos_maestros',
                'es_prerrequisito' => 0,
                'bloquea_carga_proveedor' => 0,
                'visible_para_proveedor' => 1,
                'visible_para_internos' => 1,
                'plantilla_url' => null,
                'estado' => 'Subido',
                'archivo_nombre' => 'Ficha de incorporación proveedor.pdf',
                'archivo_url' => $archivoUrl,
                'archivo_mime_type' => 'application/pdf',
                'comentario' => 'Documento generado automáticamente desde los datos de incorporación.',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        DB::table('proveedor_historial')->insert([
            'proveedor_id' => $proveedorId,
            'accion' => 'Ficha de incorporación proveedor generada automáticamente en PDF',
            'realizado_por' => 'Sistema',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $archivoUrl;
    }

    private function h($value): string
    {
        return htmlspecialchars((string) ($value ?? '—'), ENT_QUOTES, 'UTF-8');
    }

    private function row(string $label, $value): string
    {
        return '
            <tr>
                <td class="label">' . $this->h($label) . '</td>
                <td>' . $this->h($value ?: '—') . '</td>
            </tr>
        ';
    }

    private function buildHtml($p, array $sociedades): string
    {
        $sociedadesTexto = count($sociedades)
            ? implode(', ', $sociedades)
            : '—';

        $fecha = date('d-m-Y H:i');

        return '
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 11px;
        color: #1f2937;
        margin: 24px;
    }

    .header {
        border-bottom: 2px solid #4f46e5;
        padding-bottom: 12px;
        margin-bottom: 18px;
    }

    .title {
        font-size: 20px;
        font-weight: bold;
        color: #111827;
        margin: 0;
    }

    .subtitle {
        font-size: 11px;
        color: #6b7280;
        margin-top: 5px;
    }

    .section {
        margin-top: 18px;
    }

    .section-title {
        background: #eef2ff;
        color: #3730a3;
        padding: 7px 9px;
        font-weight: bold;
        border-radius: 4px;
        margin-bottom: 8px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
    }

    td {
        border: 1px solid #e5e7eb;
        padding: 6px 7px;
        vertical-align: top;
    }

    .label {
        width: 34%;
        background: #f9fafb;
        font-weight: bold;
        color: #374151;
    }

    .footer {
        margin-top: 24px;
        font-size: 9px;
        color: #6b7280;
        text-align: center;
        border-top: 1px solid #e5e7eb;
        padding-top: 8px;
    }
</style>
</head>
<body>
    <div class="header">
        <p class="title">Ficha de incorporación proveedor</p>
        <p class="subtitle">Documento generado automáticamente desde el formulario de activación — ' . $this->h($fecha) . '</p>
    </div>

    <div class="section">
        <div class="section-title">Datos generales</div>
        <table>
            ' . $this->row('Razón social', $p->razon_social) . '
            ' . $this->row('RUT', $p->rut) . '
            ' . $this->row('Giro', $p->giro) . '
            ' . $this->row('Tipo SAP', $p->tipo) . '
            ' . $this->row('Empresa', $p->empresa_nombre ?: $p->empresa_id) . '
            ' . $this->row('Estado', $p->estado) . '
            ' . $this->row('Tipo proveedor', $p->tipo_proveedor_detalle) . '
            ' . $this->row('Dimensión negocio', $p->dimension_negocio_texto) . '
        </table>
    </div>

    <div class="section">
        <div class="section-title">Dirección y contacto</div>
        <table>
            ' . $this->row('Dirección', $p->direccion) . '
            ' . $this->row('Comuna', $p->comuna) . '
            ' . $this->row('Región', $p->region) . '
            ' . $this->row('Código postal', $p->codigo_postal) . '
            ' . $this->row('País', $p->pais) . '
            ' . $this->row('Teléfono', $p->telefono) . '
            ' . $this->row('Persona contacto', $p->persona_contacto) . '
            ' . $this->row('Cargo contacto', $p->persona_contacto_cargo) . '
            ' . $this->row('Teléfono contacto', $p->persona_contacto_telefono) . '
            ' . $this->row('Email contacto', $p->persona_contacto_email) . '
        </table>
    </div>

    <div class="section">
        <div class="section-title">Representante legal</div>
        <table>
            ' . $this->row('Representante legal', $p->representante_legal) . '
            ' . $this->row('CI representante', $p->representante_ci) . '
            ' . $this->row('Dirección representante', $p->representante_direccion) . '
            ' . $this->row('Email representante', $p->representante_email) . '
            ' . $this->row('Cargo representante', $p->representante_cargo) . '
            ' . $this->row('Fecha escritura pública', $p->fecha_escritura_publica) . '
            ' . $this->row('Notaría', $p->notaria) . '
        </table>
    </div>

    <div class="section">
        <div class="section-title">Condiciones comerciales y pago</div>
        <table>
            ' . $this->row('Moneda', $p->moneda) . '
            ' . $this->row('Plazo pago', $p->plazo_pago) . '
            ' . $this->row('Forma pago', $p->forma_pago) . '
            ' . $this->row('Días despacho', $p->dias_despacho) . '
            ' . $this->row('Banco pago', $p->banco_pago) . '
            ' . $this->row('Cuenta corriente', $p->cuenta_corriente) . '
        </table>
    </div>

    <div class="section">
        <div class="section-title">Sociedades habilitadas</div>
        <table>
            ' . $this->row('Sociedades', $sociedadesTexto) . '
        </table>
    </div>

    <div class="footer">
        Portal Documental Proveedores — Documento generado automáticamente. No requiere carga manual del proveedor.
    </div>
</body>
</html>';
    }
}