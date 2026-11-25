<?php

namespace App\Services;

use App\Models\ProveedorDeclaracion;
use App\Models\ProveedorHistorial;
use App\Models\Proveedores;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Declaraciones digitales
|--------------------------------------------------------------------------
|
| Reemplaza las planillas de conflicto de interés y de relacionados/PEP
| por formularios dentro del portal.
|
| Firma electrónica simple (Ley 19.799): el declarante está autenticado,
| acepta expresamente la declaración y el portal registra fecha, IP,
| navegador y un hash SHA-256 del contenido firmado. El hash permite
| comprobar después que las respuestas no se modificaron.
|
*/
class DeclaracionService
{
    protected $pdf;
    protected $notificaciones;

    public function __construct(PdfDocumentoService $pdf, NotificacionService $notificaciones)
    {
        $this->pdf = $pdf;
        $this->notificaciones = $notificaciones;
    }

    /**
     * Normaliza y valida las respuestas. Devuelve las respuestas limpias
     * o lanza \InvalidArgumentException con el detalle del error.
     */
    public function normalizarRespuestas(string $tipo, array $input): array
    {
        if (empty($input['acepta'])) {
            throw new \InvalidArgumentException('Debes aceptar la declaración para firmarla.');
        }

        if ($tipo === ProveedorDeclaracion::TIPO_CONFLICTO) {
            $tieneRelacionados = !empty($input['tiene_relacionados']);

            $relacionados = $this->filas($input['relacionados'] ?? [], ['nombre', 'relacion', 'cargo']);

            if ($tieneRelacionados && count($relacionados) === 0) {
                throw new \InvalidArgumentException('Indicaste que tienes personas relacionadas: agrega al menos una con nombre y relación.');
            }

            return [
                'cargo'              => $this->texto($input['cargo'] ?? ''),
                'empresa'            => $this->texto($input['empresa'] ?? ''),
                'tiene_relacionados' => $tieneRelacionados,
                'relacionados'       => $tieneRelacionados ? $relacionados : [],
                'acepta'             => true,
            ];
        }

        $tieneTrabajadores = !empty($input['tiene_trabajadores_relacionados']);
        $tieneEmpresas = !empty($input['tiene_empresas_relacionadas']);
        $esPep = !empty($input['es_pep']);

        $trabajadores = $this->filas($input['trabajadores_relacionados'] ?? [], ['nombre', 'parentesco', 'cargo', 'empresa']);
        $empresas = $this->filas($input['empresas_relacionadas'] ?? [], ['razon_social', 'rut']);
        $peps = $this->filas($input['peps'] ?? [], ['nombre', 'cargo', 'relacion']);

        if ($tieneTrabajadores && count($trabajadores) === 0) {
            throw new \InvalidArgumentException('Agrega al menos un trabajador relacionado o marca que no existen.');
        }

        if ($tieneEmpresas && count($empresas) === 0) {
            throw new \InvalidArgumentException('Agrega al menos una empresa relacionada o marca que no existen.');
        }

        if ($esPep && count($peps) === 0) {
            throw new \InvalidArgumentException('Indicaste relación con PEP o funcionario público: completa el recuadro.');
        }

        if (empty($input['acepta_modelo_prevencion'])) {
            throw new \InvalidArgumentException('Debes declarar que conoces el Modelo de Prevención de Delitos (Ley 20.393).');
        }

        if ($this->texto($input['declarante_rut'] ?? '') === '') {
            throw new \InvalidArgumentException('El RUT del representante legal es obligatorio.');
        }

        return [
            'declarante_rut'                  => $this->texto($input['declarante_rut']),
            'cargo'                           => $this->texto($input['cargo'] ?? ''),
            'tiene_trabajadores_relacionados' => $tieneTrabajadores,
            'trabajadores_relacionados'       => $tieneTrabajadores ? $trabajadores : [],
            'tiene_empresas_relacionadas'     => $tieneEmpresas,
            'empresas_relacionadas'           => $tieneEmpresas ? $empresas : [],
            'es_pep'                          => $esPep,
            'peps'                            => $esPep ? $peps : [],
            'acepta_modelo_prevencion'        => true,
            'acepta'                          => true,
        ];
    }

    public function firmar(
        Proveedores $proveedor,
        string $tipo,
        array $respuestas,
        User $user,
        ?string $ip,
        ?string $userAgent
    ): ProveedorDeclaracion {
        $nombreDocumento = ProveedorDeclaracion::DOCUMENTOS[$tipo];

        $documento = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedor->id)
            ->where('nombre', $nombreDocumento)
            ->first();

        if ($documento && $documento->estado === 'Aprobado') {
            throw new \InvalidArgumentException('La declaración ya fue aprobada y no puede volver a firmarse.');
        }

        $firmadoAt = date('Y-m-d H:i:s');

        $declarante = [
            'nombre' => $user->display_name ?: $user->email,
            'email'  => $user->email,
            'rut'    => $respuestas['declarante_rut'] ?? null,
            'cargo'  => $respuestas['cargo'] ?? null,
        ];

        $hash = $this->calcularHash($proveedor->id, $tipo, $respuestas, $declarante, $firmadoAt, $user->id);

        $declaraRelacionados = $tipo === ProveedorDeclaracion::TIPO_CONFLICTO
            ? $respuestas['tiene_relacionados']
            : ($respuestas['tiene_trabajadores_relacionados'] || $respuestas['tiene_empresas_relacionadas']);

        $declaraPep = $tipo === ProveedorDeclaracion::TIPO_PEP && $respuestas['es_pep'];

        DB::beginTransaction();

        try {
            $declaracion = ProveedorDeclaracion::firstOrNew([
                'proveedor_id' => $proveedor->id,
                'tipo'         => $tipo,
            ]);

            $declaracion->fill([
                'respuestas_json'      => json_encode($respuestas, JSON_UNESCAPED_UNICODE),
                'declara_relacionados' => $declaraRelacionados,
                'declara_pep'          => $declaraPep,
                'declarante_nombre'    => $declarante['nombre'],
                'declarante_rut'       => $declarante['rut'],
                'declarante_cargo'     => $declarante['cargo'],
                'declarante_email'     => $declarante['email'],
                'firmado_por_user_id'  => $user->id,
                'firmado_at'           => $firmadoAt,
                'firma_ip'             => $ip ? substr($ip, 0, 45) : null,
                'firma_user_agent'     => $userAgent ? substr($userAgent, 0, 255) : null,
                'firma_hash'           => $hash,
            ]);

            $html = $this->construirHtml($proveedor, $declaracion, $respuestas);
            $archivoUrl = $this->pdf->renderizar($html, 'proveedor_declaraciones', $tipo, $proveedor->id);

            $declaracion->documento_id = $this->pdf->registrarDocumento(
                $proveedor->id,
                $nombreDocumento,
                $archivoUrl,
                [
                    'responsable_carga'      => $tipo === ProveedorDeclaracion::TIPO_CONFLICTO ? 'pm' : 'proveedor',
                    'area_responsable'       => 'contraloria',
                    'visible_para_proveedor' => $tipo === ProveedorDeclaracion::TIPO_PEP ? 1 : 0,
                ],
                'Declaración firmada digitalmente. Código de verificación: ' . substr($hash, 0, 16)
            );

            $declaracion->save();

            ProveedorHistorial::create([
                'proveedor_id'  => $proveedor->id,
                'accion'        => $nombreDocumento . ' firmada digitalmente',
                'realizado_por' => $declarante['nombre'],
                'created_at'    => $firmadoAt,
            ]);

            if ($declaraPep) {
                ProveedorHistorial::create([
                    'proveedor_id'  => $proveedor->id,
                    'accion'        => 'Alerta: el proveedor declara relación con PEP o funcionario público',
                    'realizado_por' => 'Sistema',
                    'created_at'    => $firmadoAt,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->notificaciones->notificarArea(
            'contraloria',
            $proveedor,
            'declaracion_firmada',
            $nombreDocumento . ' firmada',
            'La ' . $nombreDocumento . ' de ' . $proveedor->razon_social . ' fue firmada y está lista para revisión.',
            'declaracion:' . $declaracion->id
        );

        if ($declaraPep) {
            foreach (['contraloria', 'legal_crediticia'] as $area) {
                $this->notificaciones->notificarArea(
                    $area,
                    $proveedor,
                    'alerta_pep',
                    'Proveedor declara relación con PEP',
                    $proveedor->razon_social . ' declaró relación con una Persona Expuesta Políticamente o funcionario público. Se recomienda revisión reforzada en Gesintel.',
                    'pep:' . $declaracion->id
                );
            }
        }

        return $declaracion;
    }

    public function calcularHash(
        $proveedorId,
        string $tipo,
        array $respuestas,
        array $declarante,
        string $firmadoAt,
        $userId
    ): string {
        return hash('sha256', json_encode([
            'proveedor_id' => (int) $proveedorId,
            'tipo'         => $tipo,
            'respuestas'   => $respuestas,
            'declarante'   => $declarante,
            'firmado_at'   => $firmadoAt,
            'user_id'      => (int) $userId,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Recalcula el hash con los datos guardados y lo compara con el registrado.
     */
    public function verificarIntegridad(ProveedorDeclaracion $declaracion): bool
    {
        $esperado = $this->calcularHash(
            $declaracion->proveedor_id,
            $declaracion->tipo,
            $declaracion->respuestas(),
            [
                'nombre' => $declaracion->declarante_nombre,
                'email'  => $declaracion->declarante_email,
                'rut'    => $declaracion->declarante_rut,
                'cargo'  => $declaracion->declarante_cargo,
            ],
            (string) $declaracion->firmado_at,
            $declaracion->firmado_por_user_id
        );

        return hash_equals($esperado, (string) $declaracion->firma_hash);
    }

    private function construirHtml(Proveedores $proveedor, ProveedorDeclaracion $d, array $r): string
    {
        $p = $this->pdf;

        if ($d->tipo === ProveedorDeclaracion::TIPO_CONFLICTO) {
            $titulo = 'Declaración de conflicto de interés';

            $cuerpo = '
            <div class="section"><div class="section-title">I. Datos del declarante</div><table>
                ' . $p->fila('Nombre', $d->declarante_nombre) . '
                ' . $p->fila('Cargo', $r['cargo']) . '
                ' . $p->fila('Empresa', $r['empresa']) . '
                ' . $p->fila('Fecha de la declaración', $d->firmado_at) . '
            </table></div>
            <div class="section"><div class="section-title">II. Empresa en evaluación</div><table>
                ' . $p->fila('Razón social', $proveedor->razon_social) . '
                ' . $p->fila('RUT', $proveedor->rut) . '
            </table></div>
            <div class="section"><div class="section-title">III. Personas naturales relacionadas</div>
                <p>¿Algún familiar suyo u otra persona cercana trabaja o tiene participación societaria en la empresa en evaluación? <b>' . ($r['tiene_relacionados'] ? 'Sí' : 'No') . '</b></p>
                ' . ($r['tiene_relacionados'] ? $p->tabla(['Nombre completo', 'Relación', 'Cargo o función'], array_map(function ($x) {
                    return [$x['nombre'], $x['relacion'], $x['cargo']];
                }, $r['relacionados'])) : '') . '
            </div>
            <div class="section"><div class="section-title">IV. Declaración</div>
                <p>Declaro que he respondido la presente declaración de buena fe, comprometiéndome a informar a la Empresa cualquier modificación, adición o cambio que pudiese afectar lo que en este acto se suscribe.</p>
            </div>';
        } else {
            $titulo = 'Declaración de relacionados y PEP';

            $cuerpo = '
            <div class="section"><div class="section-title">I. Identificación de la empresa</div><table>
                ' . $p->fila('Razón social', $proveedor->razon_social) . '
                ' . $p->fila('RUT', $proveedor->rut) . '
                ' . $p->fila('Fecha de la declaración', $d->firmado_at) . '
            </table></div>
            <div class="section"><div class="section-title">II. Identificación del declarante (representante legal)</div><table>
                ' . $p->fila('Nombre', $d->declarante_nombre) . '
                ' . $p->fila('RUT', $r['declarante_rut']) . '
                ' . $p->fila('Cargo', $r['cargo']) . '
            </table></div>
            <div class="section"><div class="section-title">III. Personas naturales relacionadas</div>
                <p>¿Algún director, representante legal, ejecutivo u otro colaborador tiene un familiar u otra persona cercana que trabaje en las empresas del holding? <b>' . ($r['tiene_trabajadores_relacionados'] ? 'Sí' : 'No') . '</b></p>
                ' . ($r['tiene_trabajadores_relacionados'] ? $p->tabla(['Trabajador del holding', 'Parentesco', 'Cargo', 'Empresa'], array_map(function ($x) {
                    return [$x['nombre'], $x['parentesco'], $x['cargo'], $x['empresa']];
                }, $r['trabajadores_relacionados'])) : '') . '
            </div>
            <div class="section"><div class="section-title">IV. Empresas relacionadas</div>
                <p>¿Existen empresas relacionadas que operen o hayan operado con el holding en el último año? <b>' . ($r['tiene_empresas_relacionadas'] ? 'Sí' : 'No') . '</b></p>
                ' . ($r['tiene_empresas_relacionadas'] ? $p->tabla(['Razón social', 'RUT'], array_map(function ($x) {
                    return [$x['razon_social'], $x['rut']];
                }, $r['empresas_relacionadas'])) : '') . '
            </div>
            <div class="section"><div class="section-title">V. Relación con funcionario público y PEP</div>
                <p>¿Algún dueño, directivo, representante legal o ejecutivo de la empresa, o un familiar de estos, es funcionario público nacional, extranjero o PEP? <b>' . ($r['es_pep'] ? 'Sí' : 'No') . '</b></p>
                ' . ($r['es_pep'] ? $p->tabla(['Nombre', 'Cargo o tipo de PEP', 'Relación'], array_map(function ($x) {
                    return [$x['nombre'], $x['cargo'], $x['relacion']];
                }, $r['peps'])) : '') . '
            </div>
            <div class="section"><div class="section-title">VI. Declaración</div>
                <p>Declaro conocer que las empresas del holding cuentan con un Modelo de Prevención de Delitos según la Ley 20.393, y que la empresa que represento no ha sido investigada ni sancionada por los delitos que contempla. Respondo esta declaración de buena fe y me comprometo a informar cualquier cambio.</p>
            </div>';
        }

        $cuerpo .= '
            <div class="firma">
                <b>Firma electrónica simple</b><br>
                Firmado por: ' . $p->h($d->declarante_nombre) . ' (' . $p->h($d->declarante_email) . ')<br>
                Fecha y hora: ' . $p->h($d->firmado_at) . '<br>
                Dirección IP: ' . $p->h($d->firma_ip) . '<br>
                Código de verificación (SHA-256):<br><span class="hash">' . $p->h($d->firma_hash) . '</span>
            </div>';

        return $p->documentoHtml($titulo, 'Formulario digital firmado en el Portal de Proveedores', $cuerpo);
    }

    private function filas($filas, array $campos): array
    {
        if (!is_array($filas)) {
            return [];
        }

        $resultado = [];

        foreach ($filas as $fila) {
            if (!is_array($fila)) {
                continue;
            }

            $limpia = [];

            foreach ($campos as $campo) {
                $limpia[$campo] = $this->texto($fila[$campo] ?? '');
            }

            /*
             * El primer campo (nombre o razón social) es obligatorio en cada fila.
             */
            if ($limpia[$campos[0]] === '') {
                continue;
            }

            $resultado[] = $limpia;
        }

        return $resultado;
    }

    private function texto($valor): string
    {
        return mb_substr(trim((string) $valor), 0, 255);
    }
}
