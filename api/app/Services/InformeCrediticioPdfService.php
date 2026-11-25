<?php

namespace App\Services;

use App\Models\ProveedorDeclaracion;
use App\Models\ProveedorEvaluacionCrediticia;
use App\Models\ProveedorHistorial;
use App\Models\Proveedores;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Informe de evaluación crediticia
|--------------------------------------------------------------------------
|
| Reemplaza el documento Word que consolidaba a mano las consultas de
| Legal Crediticia. Reúne en un PDF los datos del proveedor y de su
| representante legal, el resultado de cada regla, las consultas DICOM
| y Gesintel registradas y las declaraciones firmadas.
|
*/
class InformeCrediticioPdfService
{
    public const NOMBRE_DOCUMENTO = 'Informe de evaluación crediticia';

    protected $pdf;

    public function __construct(PdfDocumentoService $pdf)
    {
        $this->pdf = $pdf;
    }

    public function generar(ProveedorEvaluacionCrediticia $evaluacion, User $user): string
    {
        $proveedor = Proveedores::with(['detalle', 'creadoPor'])->findOrFail($evaluacion->proveedor_id);

        $html = $this->construirHtml($proveedor, $evaluacion, $user);
        $archivoUrl = $this->pdf->renderizar($html, 'proveedor_informes', 'informe_crediticio', $proveedor->id);

        $documentoId = $this->pdf->registrarDocumento(
            $proveedor->id,
            self::NOMBRE_DOCUMENTO,
            $archivoUrl,
            [
                'responsable_carga'      => 'interno',
                'area_responsable'       => 'legal_crediticia',
                'visible_para_proveedor' => 0,
            ],
            'Informe generado desde la evaluación #' . $evaluacion->id . ' (resultado ' . strtoupper($evaluacion->resultado) . ').'
        );

        $evaluacion->informe_documento_id = $documentoId;
        $evaluacion->save();

        ProveedorHistorial::create([
            'proveedor_id'  => $proveedor->id,
            'accion'        => self::NOMBRE_DOCUMENTO . ' generado (evaluación #' . $evaluacion->id . ')',
            'realizado_por' => $user->display_name ?: $user->email,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return $archivoUrl;
    }

    private function construirHtml(Proveedores $proveedor, ProveedorEvaluacionCrediticia $evaluacion, User $user): string
    {
        $p = $this->pdf;
        $d = $proveedor->detalle;

        $estados = [
            EvaluacionCrediticiaService::CUMPLE      => 'Cumple',
            EvaluacionCrediticiaService::ADVERTENCIA => 'Advertencia',
            EvaluacionCrediticiaService::NO_CUMPLE   => 'No cumple',
        ];

        $filasReglas = array_map(function ($regla) use ($estados) {
            return [$regla['nombre'], $estados[$regla['estado']] ?? $regla['estado'], $regla['detalle']];
        }, $evaluacion->reglas());

        $dicom = DB::table('proveedor_dicom_checks')
            ->where('proveedor_id', $proveedor->id)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($c) {
                return [$c->created_at, $c->endpoint . ($c->segmento ? ' / ' . $c->segmento : ''), $c->rut, $c->http_code];
            })
            ->toArray();

        $aml = DB::table('proveedor_aml_checks')
            ->where('proveedor_id', $proveedor->id)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($c) {
                return [$c->created_at, $c->nombre ?: $c->rut, $c->aml_status, $c->pepsan_color, $c->kycame_color];
            })
            ->toArray();

        $declaraciones = ProveedorDeclaracion::where('proveedor_id', $proveedor->id)
            ->get()
            ->map(function ($x) {
                return [
                    ProveedorDeclaracion::DOCUMENTOS[$x->tipo] ?? $x->tipo,
                    $x->declarante_nombre,
                    $x->firmado_at,
                    $x->declara_relacionados ? 'Sí' : 'No',
                    $x->declara_pep ? 'Sí' : 'No',
                ];
            })
            ->toArray();

        $resultado = $evaluacion->resultado;

        $cuerpo = '
        <div class="section"><div class="section-title">Resultado</div>
            <p><span class="semaforo ' . $p->h($resultado) . '">' . $p->h(strtoupper($resultado)) . '</span>
            &nbsp; Recomendación automática según las reglas del procedimiento. La decisión final corresponde a Legal Crediticia.</p>
            <table>
                ' . $p->fila('Evaluación', '#' . $evaluacion->id . ' (' . ($evaluacion->origen === 'reevaluacion' ? 're-evaluación periódica' : 'evaluación manual') . ')') . '
                ' . $p->fila('Fecha de evaluación', $evaluacion->created_at) . '
                ' . $p->fila('Observaciones del analista', $evaluacion->observaciones) . '
            </table>
        </div>
        <div class="section"><div class="section-title">Proveedor</div><table>
            ' . $p->fila('Razón social', $proveedor->razon_social) . '
            ' . $p->fila('RUT', $proveedor->rut) . '
            ' . $p->fila('Giro', $proveedor->giro) . '
            ' . $p->fila('Tipo de proveedor', $d ? $d->tipo_proveedor_detalle : null) . '
            ' . $p->fila('Inicio de actividades', $evaluacion->fecha_inicio_actividades) . '
            ' . $p->fila('Solicitante', $proveedor->creadoPor ? $proveedor->creadoPor->display_name : null) . '
        </table></div>
        <div class="section"><div class="section-title">Representante legal</div><table>
            ' . $p->fila('Nombre', $d ? $d->representante_legal : null) . '
            ' . $p->fila('RUT / CI', $d ? $d->representante_ci : null) . '
            ' . $p->fila('Cargo', $d ? $d->representante_cargo : null) . '
            ' . $p->fila('Correo', $d ? $d->representante_email : null) . '
        </table></div>
        <div class="section"><div class="section-title">Reglas evaluadas</div>
            ' . $p->tabla(['Regla', 'Estado', 'Detalle'], $filasReglas) . '
        </div>
        <div class="section"><div class="section-title">Consultas DICOM registradas</div>
            ' . $p->tabla(['Fecha', 'Consulta', 'RUT', 'Código HTTP'], $dicom) . '
        </div>
        <div class="section"><div class="section-title">Consultas Gesintel registradas</div>
            ' . $p->tabla(['Fecha', 'Consultado', 'Estado AML', 'PEPSAN', 'KYCAME'], $aml) . '
        </div>
        <div class="section"><div class="section-title">Declaraciones firmadas</div>
            ' . $p->tabla(['Declaración', 'Firmada por', 'Fecha', 'Relacionados', 'PEP'], $declaraciones) . '
        </div>
        <div class="firma">Informe emitido por ' . $p->h($user->display_name ?: $user->email) . ' el ' . $p->h(date('Y-m-d H:i:s')) . '.</div>';

        return $p->documentoHtml(
            self::NOMBRE_DOCUMENTO,
            $proveedor->razon_social . ' — ' . $proveedor->rut,
            $cuerpo
        );
    }
}
