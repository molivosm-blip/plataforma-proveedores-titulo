<?php

namespace App\Http\Controllers;

use App\Models\ProveedorEvaluacionCrediticia;
use App\Models\Proveedores;
use App\Services\AreaPermissionService;
use App\Services\EvaluacionCrediticiaService;
use App\Services\InformeCrediticioPdfService;
use App\Services\PlazosService;
use Illuminate\Http\Request;

class EvaluacionCrediticiaController extends Controller
{
    private const AREA = 'legal_crediticia';

    protected $permissions;
    protected $evaluaciones;
    protected $informes;
    protected $plazos;

    public function __construct(
        AreaPermissionService $permissions,
        EvaluacionCrediticiaService $evaluaciones,
        InformeCrediticioPdfService $informes,
        PlazosService $plazos
    ) {
        $this->permissions = $permissions;
        $this->evaluaciones = $evaluaciones;
        $this->informes = $informes;
        $this->plazos = $plazos;
    }

    /**
     * Historial de evaluaciones y vista previa de las reglas con los datos actuales.
     *
     * GET /v1/interno/proveedores/{id}/evaluacion-crediticia
     */
    public function index(Request $request, $id)
    {
        try {
            $user = $request->auth;
            $proveedor = Proveedores::findOrFail($id);

            if (!$this->permissions->actsForArea($user, self::AREA)) {
                return $this->responseData(['error' => true, 'message' => 'Solo Legal Crediticia puede ver la evaluación crediticia'], 403);
            }

            $evaluaciones = ProveedorEvaluacionCrediticia::with('creadoPor')
                ->where('proveedor_id', $proveedor->id)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($e) {
                    return $this->map($e);
                })
                ->values();

            return $this->responseData(['data' => ['evaluaciones' => $evaluaciones]], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Registra una evaluación con los datos ingresados por el analista.
     *
     * POST /v1/interno/proveedores/{id}/evaluacion-crediticia
     */
    public function store(Request $request, $id)
    {
        try {
            $user = $request->auth;
            $proveedor = Proveedores::findOrFail($id);

            if (!$this->permissions->actsForArea($user, self::AREA)) {
                return $this->responseData(['error' => true, 'message' => 'Solo Legal Crediticia puede registrar la evaluación crediticia'], 403);
            }

            $this->validate($request, [
                'fecha_inicio_actividades'      => 'nullable|date|before_or_equal:today',
                'dicom_resultado'               => 'required|in:sin_morosidad,con_morosidad,no_consultado',
                'dicom_representante_resultado' => 'required|in:sin_morosidad,con_morosidad,no_consultado',
                'observaciones'                 => 'nullable|string|max:2000',
            ]);

            $evaluacion = $this->evaluaciones->evaluar(
                $proveedor,
                $request->only([
                    'fecha_inicio_actividades',
                    'dicom_resultado',
                    'dicom_representante_resultado',
                    'observaciones',
                ]),
                'manual',
                $user
            );

            return $this->responseData([
                'message' => 'Evaluación registrada',
                'data'    => $this->map($evaluacion->load('creadoPor')),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->responseData(['error' => true, 'message' => 'Datos inválidos', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Repite la evaluación con los datos de la evaluación anterior.
     *
     * POST /v1/interno/proveedores/{id}/evaluacion-crediticia/reevaluar
     */
    public function reevaluar(Request $request, $id)
    {
        try {
            $user = $request->auth;
            $proveedor = Proveedores::findOrFail($id);

            if (!$this->permissions->actsForArea($user, self::AREA)) {
                return $this->responseData(['error' => true, 'message' => 'No autorizado'], 403);
            }

            $evaluacion = $this->evaluaciones->reevaluar($proveedor);

            return $this->responseData([
                'message' => 'Re-evaluación registrada',
                'data'    => $this->map($evaluacion),
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Genera el informe PDF de una evaluación.
     *
     * POST /v1/interno/proveedores/{id}/evaluacion-crediticia/{evaluacionId}/informe
     */
    public function informe(Request $request, $id, $evaluacionId)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->actsForArea($user, self::AREA)) {
                return $this->responseData(['error' => true, 'message' => 'No autorizado'], 403);
            }

            $evaluacion = ProveedorEvaluacionCrediticia::where('proveedor_id', $id)
                ->where('id', $evaluacionId)
                ->firstOrFail();

            $archivoUrl = $this->informes->generar($evaluacion, $user);

            return $this->responseData([
                'message' => 'Informe generado',
                'data'    => [
                    'archivoUrl'  => $archivoUrl,
                    'evaluacion'  => $this->map($evaluacion->load('creadoPor')),
                ],
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Plazos por etapa del proveedor.
     *
     * GET /v1/interno/proveedores/{id}/plazos
     */
    public function plazos(Request $request, $id)
    {
        try {
            $user = $request->auth;
            $proveedor = Proveedores::findOrFail($id);

            if (!$this->permissions->canViewProveedor($user, $proveedor)) {
                return $this->responseData(['error' => true, 'message' => 'No autorizado'], 403);
            }

            return $this->responseData([
                'data' => [
                    'plazoDias' => $this->plazos->plazoDias(),
                    'etapas'    => $this->plazos->etapas($proveedor),
                ],
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    private function map(ProveedorEvaluacionCrediticia $e): array
    {
        return [
            'id'                     => (string) $e->id,
            'proveedorId'            => (string) $e->proveedor_id,
            'resultado'              => $e->resultado,
            'reglas'                 => $e->reglas(),
            'fechaInicioActividades' => $e->fecha_inicio_actividades,
            'dicomResultado'         => $e->dicom_resultado,
            'observaciones'          => $e->observaciones,
            'origen'                 => $e->origen,
            'informeDocumentoId'     => $e->informe_documento_id ? (string) $e->informe_documento_id : null,
            'creadoPor'              => $e->creadoPor ? ($e->creadoPor->display_name ?: $e->creadoPor->email) : 'Sistema',
            'createdAt'              => $e->created_at,
        ];
    }
}
