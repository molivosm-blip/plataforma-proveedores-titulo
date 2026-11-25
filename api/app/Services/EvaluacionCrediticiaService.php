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
| Evaluación crediticia con semáforo
|--------------------------------------------------------------------------
|
| Aplica las reglas del procedimiento de creación de proveedores y entrega
| una recomendación verde, amarilla o roja a Legal Crediticia. La decisión
| final sigue siendo del área: el semáforo no aprueba ni rechaza solo.
|
| Reglas:
| 1. Inicio de actividades mayor a un año.
| 2. Referencia comercial cargada y aprobada.
| 3. Sin morosidad en DICOM (empresa).
| 4. Sin morosidad en DICOM (representante legal).
| 5. Gesintel sin alertas rojas.
| 6. Declaración de relacionados y PEP.
| 7. Declaración de conflicto de interés del solicitante.
| 8. Vigencia de las consultas (DICOM y Gesintel) para la re-evaluación.
|
| Cada regla queda como cumple, advertencia o no_cumple. Basta una
| no_cumple para que el resultado sea rojo; una advertencia lo deja amarillo.
|
*/
class EvaluacionCrediticiaService
{
    public const CUMPLE = 'cumple';
    public const ADVERTENCIA = 'advertencia';
    public const NO_CUMPLE = 'no_cumple';

    private const ORDEN_RESULTADO = ['verde' => 0, 'amarillo' => 1, 'rojo' => 2];

    protected $notificaciones;
    protected $dicom;

    public function __construct(NotificacionService $notificaciones, DicomService $dicom)
    {
        $this->notificaciones = $notificaciones;
        $this->dicom = $dicom;
    }

    /**
     * $datos: fecha_inicio_actividades (Y-m-d|null), dicom_resultado,
     * dicom_representante_resultado y observaciones.
     */
    public function evaluar(
        Proveedores $proveedor,
        array $datos,
        string $origen,
        ?User $user
    ): ProveedorEvaluacionCrediticia {
        $reglas = $this->calcularReglas($proveedor, $datos);
        $resultado = $this->resultado($reglas);

        /*
         * Se guarda el resultado DICOM efectivo (manual o leído de la consulta).
         */
        foreach ($reglas as $regla) {
            if ($regla['clave'] === 'dicom_empresa') {
                $datos['dicom_resultado'] = $regla['valor'];
            }
        }

        $anterior = $this->ultima($proveedor->id);

        $evaluacion = ProveedorEvaluacionCrediticia::create([
            'proveedor_id'             => $proveedor->id,
            'resultado'                => $resultado,
            'reglas_json'              => json_encode($reglas, JSON_UNESCAPED_UNICODE),
            'fecha_inicio_actividades' => $datos['fecha_inicio_actividades'] ?? null,
            'dicom_resultado'          => $datos['dicom_resultado'] ?? 'no_consultado',
            'observaciones'            => $datos['observaciones'] ?? null,
            'origen'                   => $origen,
            'created_by_user_id'       => $user ? $user->id : null,
            'created_at'               => date('Y-m-d H:i:s'),
        ]);

        ProveedorHistorial::create([
            'proveedor_id'  => $proveedor->id,
            'accion'        => ($origen === 'reevaluacion' ? 'Re-evaluación periódica' : 'Evaluación crediticia')
                . ' registrada con resultado ' . strtoupper($resultado),
            'realizado_por' => $user ? ($user->display_name ?: $user->email) : 'Sistema',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        /*
         * Si la re-evaluación empeora el resultado, Legal Crediticia recibe una alerta.
         */
        if (
            $origen === 'reevaluacion' &&
            $anterior &&
            self::ORDEN_RESULTADO[$resultado] > self::ORDEN_RESULTADO[$anterior->resultado]
        ) {
            $this->notificaciones->notificarArea(
                'legal_crediticia',
                $proveedor,
                'reevaluacion_alerta',
                'Re-evaluación con resultado ' . strtoupper($resultado),
                'La re-evaluación periódica de ' . $proveedor->razon_social . ' pasó de '
                    . strtoupper($anterior->resultado) . ' a ' . strtoupper($resultado)
                    . '. Revisa las reglas que cambiaron.',
                'reevaluacion:' . $evaluacion->id
            );
        }

        return $evaluacion;
    }

    /**
     * Repite la evaluación con los datos manuales de la última evaluación
     * y la información actual del proveedor.
     */
    public function reevaluar(Proveedores $proveedor): ProveedorEvaluacionCrediticia
    {
        $anterior = $this->ultima($proveedor->id);
        $observaciones = 'Re-evaluación automática a partir de la evaluación anterior.';

        /*
         * Se vuelve a consultar DICOM para la empresa y el representante legal.
         * Si el servicio no responde, se usa la última consulta registrada y la
         * regla de vigencia advierte si está vencida.
         */
        $consultasNuevas = 0;

        foreach (['empresa', 'persona'] as $segmento) {
            try {
                $this->dicom->consultarDicom($proveedor->id, $segmento, null);
                $consultasNuevas++;
            } catch (\Throwable $e) {
                $observaciones .= ' DICOM ' . $segmento . ' no disponible: ' . $e->getMessage();
            }
        }

        if ($consultasNuevas > 0) {
            $observaciones .= ' Se realizaron ' . $consultasNuevas . ' consulta(s) DICOM nuevas.';
        }

        /*
         * El resultado DICOM se toma de la consulta (no_consultado = automático).
         * Solo la fecha de inicio de actividades viene de la evaluación anterior.
         */
        return $this->evaluar($proveedor, [
            'fecha_inicio_actividades'      => $anterior ? $anterior->fecha_inicio_actividades : null,
            'dicom_resultado'               => 'no_consultado',
            'dicom_representante_resultado' => 'no_consultado',
            'observaciones'                 => $observaciones,
            'verificar_vigencia'            => true,
        ], 'reevaluacion', null);
    }

    /**
     * Proveedores aprobados cuya última evaluación es más antigua que el período indicado.
     */
    public function proveedoresParaReevaluar(int $meses)
    {
        $limite = date('Y-m-d H:i:s', strtotime('-' . $meses . ' months'));

        return Proveedores::where('estado', 'Aprobado')
            ->whereNotExists(function ($q) use ($limite) {
                $q->select(DB::raw(1))
                    ->from('proveedor_evaluaciones_crediticias as e')
                    ->whereColumn('e.proveedor_id', 'proveedores.id')
                    ->where('e.created_at', '>=', $limite);
            })
            ->get();
    }

    public function ultima($proveedorId): ?ProveedorEvaluacionCrediticia
    {
        return ProveedorEvaluacionCrediticia::where('proveedor_id', $proveedorId)
            ->orderBy('id', 'desc')
            ->first();
    }

    public function calcularReglas(Proveedores $proveedor, array $datos): array
    {
        $reglas = [];

        $reglas[] = $this->reglaAntiguedad($datos['fecha_inicio_actividades'] ?? null);
        $reglas[] = $this->reglaReferenciaComercial($proveedor);
        $reglas[] = $this->reglaDicom(
            'dicom_empresa',
            'DICOM de la empresa',
            $datos['dicom_resultado'] ?? 'no_consultado',
            $this->dicom->ultimaConsulta($proveedor->id, 'empresa')
        );
        $reglas[] = $this->reglaDicom(
            'dicom_representante',
            'DICOM del representante legal',
            $datos['dicom_representante_resultado'] ?? 'no_consultado',
            $this->dicom->ultimaConsulta($proveedor->id, 'persona')
        );
        $reglas[] = $this->reglaGesintel($proveedor);
        $reglas[] = $this->reglaDeclaracionPep($proveedor);
        $reglas[] = $this->reglaConflictoInteres($proveedor);

        if (!empty($datos['verificar_vigencia'])) {
            $reglas[] = $this->reglaVigencia($proveedor);
        }

        return $reglas;
    }

    public function resultado(array $reglas): string
    {
        $estados = array_column($reglas, 'estado');

        if (in_array(self::NO_CUMPLE, $estados, true)) {
            return 'rojo';
        }

        if (in_array(self::ADVERTENCIA, $estados, true)) {
            return 'amarillo';
        }

        return 'verde';
    }

    /*
    |--------------------------------------------------------------------------
    | Reglas
    |--------------------------------------------------------------------------
    */

    private function reglaAntiguedad(?string $fechaInicio): array
    {
        $nombre = 'Inicio de actividades mayor a un año';

        if (empty($fechaInicio) || !strtotime($fechaInicio)) {
            return $this->regla('antiguedad', $nombre, self::ADVERTENCIA, 'No se registró la fecha de inicio de actividades (SII).');
        }

        $dias = (int) floor((time() - strtotime($fechaInicio)) / 86400);
        $valor = date('d-m-Y', strtotime($fechaInicio));

        if ($dias >= 365) {
            return $this->regla('antiguedad', $nombre, self::CUMPLE, 'Inicio de actividades el ' . $valor . ' (' . floor($dias / 365) . ' año(s)).', $fechaInicio);
        }

        return $this->regla('antiguedad', $nombre, self::NO_CUMPLE, 'Inicio de actividades el ' . $valor . ': menos de un año.', $fechaInicio);
    }

    private function reglaReferenciaComercial(Proveedores $proveedor): array
    {
        $nombre = 'Referencia comercial';

        $documento = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedor->id)
            ->where('nombre', 'Referencia comercial')
            ->first();

        if (!$documento || in_array($documento->estado, ['Pendiente', 'Observado'], true)) {
            $estado = $documento ? $documento->estado : 'no solicitada';

            return $this->regla('referencia_comercial', $nombre, self::NO_CUMPLE, 'La referencia comercial está ' . mb_strtolower($estado) . '.');
        }

        if ($documento->estado === 'Subido') {
            return $this->regla('referencia_comercial', $nombre, self::ADVERTENCIA, 'La referencia comercial fue cargada, pero aún no está aprobada.');
        }

        return $this->regla('referencia_comercial', $nombre, self::CUMPLE, 'Referencia comercial aprobada.');
    }

    /**
     * $resultado es lo que ingresó el analista. Con "no_consultado" se usa
     * automáticamente la última consulta DICOM registrada en el portal.
     */
    private function reglaDicom(string $clave, string $nombre, string $resultado, $consulta = null): array
    {
        $origen = 'Ingresado por el analista.';

        if ($resultado === 'no_consultado' && $consulta) {
            $automatico = $this->dicom->interpretar($consulta);
            $fecha = date('d-m-Y', strtotime((string) $consulta->created_at));
            $simulada = strpos((string) $consulta->request_url, 'simulado://') === 0 ? ' (servicio simulado)' : '';

            if ($automatico) {
                $resultado = $automatico;
                $origen = 'Según la consulta DICOM del ' . $fecha . $simulada . ' para el RUT ' . $consulta->rut . '.';
            } else {
                return $this->regla($clave, $nombre, self::ADVERTENCIA, 'La consulta DICOM del ' . $fecha . ' no trae un resultado reconocible: debe revisarla el analista.', 'no_consultado');
            }
        }

        if ($resultado === 'sin_morosidad') {
            return $this->regla($clave, $nombre, self::CUMPLE, 'Sin morosidad. ' . $origen, $resultado);
        }

        if ($resultado === 'con_morosidad') {
            return $this->regla($clave, $nombre, self::NO_CUMPLE, 'Presenta morosidad o protestos. ' . $origen, $resultado);
        }

        return $this->regla($clave, $nombre, self::ADVERTENCIA, 'No hay consulta DICOM registrada ni resultado ingresado.', 'no_consultado');
    }

    private function reglaGesintel(Proveedores $proveedor): array
    {
        $nombre = 'Gesintel (PEP, sanciones y listas)';

        $check = DB::table('proveedor_aml_checks')
            ->where('proveedor_id', $proveedor->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$check) {
            return $this->regla('gesintel', $nombre, self::ADVERTENCIA, 'No hay consultas Gesintel registradas para este proveedor.');
        }

        $colores = array_filter([
            $check->pepsan_color,
            $check->kycame_color,
            $check->ubocom_color,
        ]);

        $fecha = $check->created_at ? date('d-m-Y', strtotime($check->created_at)) : 'sin fecha';

        foreach ($colores as $color) {
            if ($this->esColor($color, ['red', 'roj'])) {
                return $this->regla('gesintel', $nombre, self::NO_CUMPLE, 'Consulta del ' . $fecha . ' con alertas rojas.');
            }
        }

        foreach ($colores as $color) {
            if ($this->esColor($color, ['yellow', 'amar', 'orange', 'naran'])) {
                return $this->regla('gesintel', $nombre, self::ADVERTENCIA, 'Consulta del ' . $fecha . ' con alertas amarillas.');
            }
        }

        return $this->regla('gesintel', $nombre, self::CUMPLE, 'Consulta del ' . $fecha . ' sin alertas.');
    }

    private function reglaDeclaracionPep(Proveedores $proveedor): array
    {
        $nombre = 'Declaración de relacionados y PEP';

        $requerida = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedor->id)
            ->where('nombre', ProveedorDeclaracion::DOCUMENTOS[ProveedorDeclaracion::TIPO_PEP])
            ->exists();

        $declaracion = ProveedorDeclaracion::where('proveedor_id', $proveedor->id)
            ->where('tipo', ProveedorDeclaracion::TIPO_PEP)
            ->first();

        if (!$declaracion) {
            return $requerida
                ? $this->regla('declaracion_pep', $nombre, self::ADVERTENCIA, 'El proveedor aún no firma la declaración.')
                : $this->regla('declaracion_pep', $nombre, self::CUMPLE, 'No aplica para este tipo de proveedor.');
        }

        if ($declaracion->declara_pep) {
            return $this->regla('declaracion_pep', $nombre, self::ADVERTENCIA, 'Declara relación con PEP o funcionario público: requiere revisión reforzada.');
        }

        if ($declaracion->declara_relacionados) {
            return $this->regla('declaracion_pep', $nombre, self::ADVERTENCIA, 'Declara personas o empresas relacionadas con el holding.');
        }

        return $this->regla('declaracion_pep', $nombre, self::CUMPLE, 'Sin relacionados ni PEP declarados.');
    }

    private function reglaConflictoInteres(Proveedores $proveedor): array
    {
        $nombre = 'Conflicto de interés del solicitante';

        $declaracion = ProveedorDeclaracion::where('proveedor_id', $proveedor->id)
            ->where('tipo', ProveedorDeclaracion::TIPO_CONFLICTO)
            ->first();

        if (!$declaracion) {
            return $this->regla('conflicto_interes', $nombre, self::ADVERTENCIA, 'El solicitante no ha firmado la declaración digital.');
        }

        if ($declaracion->declara_relacionados) {
            return $this->regla('conflicto_interes', $nombre, self::ADVERTENCIA, 'El solicitante declara personas relacionadas con el proveedor.');
        }

        return $this->regla('conflicto_interes', $nombre, self::CUMPLE, 'El solicitante declara no tener conflicto de interés.');
    }

    private function reglaVigencia(Proveedores $proveedor): array
    {
        $nombre = 'Vigencia de las consultas externas';
        $meses = (int) env('REEVALUACION_VIGENCIA_CONSULTAS_MESES', 12);
        $limite = strtotime('-' . $meses . ' months');

        $vencidas = [];

        foreach (['proveedor_dicom_checks' => 'DICOM', 'proveedor_aml_checks' => 'Gesintel'] as $tabla => $label) {
            $ultima = DB::table($tabla)
                ->where('proveedor_id', $proveedor->id)
                ->max('created_at');

            if (!$ultima || strtotime($ultima) < $limite) {
                $vencidas[] = $label;
            }
        }

        if (count($vencidas) > 0) {
            return $this->regla(
                'vigencia_consultas',
                $nombre,
                self::ADVERTENCIA,
                'Consultas sin realizar en los últimos ' . $meses . ' meses: ' . implode(', ', $vencidas) . '. Se recomienda consultar nuevamente.'
            );
        }

        return $this->regla('vigencia_consultas', $nombre, self::CUMPLE, 'Las consultas externas están vigentes.');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function regla(string $clave, string $nombre, string $estado, string $detalle, $valor = null): array
    {
        return [
            'clave'   => $clave,
            'nombre'  => $nombre,
            'estado'  => $estado,
            'detalle' => $detalle,
            'valor'   => $valor,
        ];
    }

    private function valorRegla(array $reglas, string $clave)
    {
        foreach ($reglas as $regla) {
            if (($regla['clave'] ?? null) === $clave) {
                return $regla['valor'] ?? null;
            }
        }

        return null;
    }

    private function esColor(?string $color, array $patrones): bool
    {
        $color = mb_strtolower((string) $color);

        foreach ($patrones as $patron) {
            if (strpos($color, $patron) !== false) {
                return true;
            }
        }

        return false;
    }
}
