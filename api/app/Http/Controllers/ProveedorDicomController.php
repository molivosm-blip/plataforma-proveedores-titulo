<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Proveedores;
use App\Services\DicomService;
use App\Services\AreaPermissionService;

class ProveedorDicomController extends Controller
{
    protected $dicomService;
    protected $permissions;

    public function __construct(
        DicomService $dicomService,
        AreaPermissionService $permissions
    ) {
        $this->dicomService = $dicomService;
        $this->permissions = $permissions;
    }

    public function consultar(Request $request, $rut, $segmento)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseDicomApi($user)) {
                return $this->dicomForbiddenResponse();
            }

            $segmento = $this->validarSegmento((string) $segmento);

            $proveedor = $this->findProveedorVisibleByRutOrFail($rut, $user);

            $check = $this->dicomService->consultarDicom(
                (int) $proveedor->id,
                $segmento,
                $user
            );

            return $this->responseData([
                'message' => 'Consulta DICOM realizada correctamente',
                'data' => $this->mapCheck($check),
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function historial(Request $request, $rut)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseDicomApi($user)) {
                return $this->dicomForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleByRutOrFail($rut, $user);

            $check = $this->dicomService->consultarHistorial(
                (int) $proveedor->id,
                $user
            );

            return $this->responseData([
                'message' => 'Consulta historial DICOM realizada correctamente',
                'data' => $this->mapCheck($check),
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function informacion(Request $request, $rut)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseDicomApi($user)) {
                return $this->dicomForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleByRutOrFail($rut, $user);

            $check = $this->dicomService->consultarInformacion(
                (int) $proveedor->id,
                $user
            );

            return $this->responseData([
                'message' => 'Consulta información DICOM realizada correctamente',
                'data' => $this->mapCheck($check),
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function legado(Request $request, $rut, $fecha)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseDicomApi($user)) {
                return $this->dicomForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleByRutOrFail($rut, $user);

            $check = $this->dicomService->consultarLegado(
                (int) $proveedor->id,
                (string) $fecha,
                $user
            );

            return $this->responseData([
                'message' => 'Consulta legado DICOM realizada correctamente',
                'data' => $this->mapCheck($check),
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function checks(Request $request, $rut)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseDicomApi($user)) {
                return $this->dicomForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleByRutOrFail($rut, $user);

            $checks = $this->dicomService
                ->obtenerChecksProveedor((int) $proveedor->id)
                ->map(function ($check) {
                    return $this->mapCheck($check);
                })
                ->values();

            return $this->responseData([
                'data' => $checks,
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function canUseDicomApi($user): bool
    {
        if (!$user) {
            return false;
        }

        if (!$this->permissions->isInternalUser($user)) {
            return false;
        }

        $allowedAreas = [
            'contraloria',
            'legal_crediticia',
        ];

        $area = isset($user->area)
            ? strtolower(trim((string) $user->area))
            : '';

        return in_array($area, $allowedAreas, true);
    }

    private function dicomForbiddenResponse()
    {
        return $this->responseData([
            'error' => true,
            'message' => 'No tienes permisos para usar consultas DICOM. Solo Contraloría y Legal Crediticia pueden utilizar esta función.',
        ], 403);
    }

    private function validarSegmento(string $segmento): string
    {
        $segmento = strtolower(trim($segmento));

        $permitidos = [
            'persona',
            'empresa',
        ];

        if (!in_array($segmento, $permitidos, true)) {
            throw new \Exception('Segmento DICOM inválido. Usa persona o empresa.');
        }

        return $segmento;
    }

    private function findProveedorVisibleByRutOrFail($rut, $user)
    {
        if (!$this->permissions->isInternalUser($user)) {
            abort(403, 'No autorizado');
        }

        $rutNormalizado = $this->normalizeRut($rut);

        if ($rutNormalizado === '') {
            abort(422, 'RUT de proveedor no válido.');
        }

        $query = Proveedores::with([
            'usuarioProveedor',
            'creadoPor',
            'documentos',
            'historial',
            'detalle',
            'sociedadesHabilitadas',
            'workflowSteps',
            'validaciones',
        ]);

        $this->permissions->applyProveedorVisibilityQuery($query, $user);

        $proveedor = $query
            ->whereRaw(
                "UPPER(REPLACE(REPLACE(REPLACE(rut, '.', ''), '-', ''), ' ', '')) = ?",
                [$rutNormalizado]
            )
            ->first();

        if (!$proveedor) {
            abort(404, 'Proveedor no encontrado o no visible para el usuario.');
        }

        return $proveedor;
    }

    private function mapCheck($check): array
    {
        return [
            'id' => (string) $check->id,
            'proveedorId' => $check->proveedor_id
                ? (string) $check->proveedor_id
                : null,
            'endpoint' => $check->endpoint,
            'segmento' => $check->segmento,
            'rut' => $check->rut,
            'fechaConsulta' => $check->fecha_consulta,
            'response' => $this->decodeJson($check->response_json),
            'httpCode' => $check->http_code,
            'createdByUserId' => $check->created_by_user_id
                ? (string) $check->created_by_user_id
                : null,
            'createdAt' => $check->created_at,
            'updatedAt' => $check->updated_at,
        ];
    }

    private function normalizeRut(?string $rut): string
    {
        $value = trim((string) $rut);

        $value = str_replace(['.', '-', ' '], '', $value);

        return strtoupper($value);
    }

    private function decodeJson(?string $json)
    {
        if (!$json) {
            return null;
        }

        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : $json;
    }
}