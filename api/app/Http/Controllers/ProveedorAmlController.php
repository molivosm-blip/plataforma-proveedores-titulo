<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Proveedores;
use App\Services\AmlUpdateService;
use App\Services\AreaPermissionService;

class ProveedorAmlController extends Controller
{
    protected $amlService;
    protected $permissions;

    public function __construct(
        AmlUpdateService $amlService,
        AreaPermissionService $permissions
    ) {
        $this->amlService = $amlService;
        $this->permissions = $permissions;
    }

    /*
    |--------------------------------------------------------------------------
    | Consultas AML
    |--------------------------------------------------------------------------
    */

    public function risk(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $check = $this->amlService
                ->consultarRiesgoProveedor(
                    (int) $proveedor->id,
                    $user
                );

            return $this->responseData([
                'message' =>
                    'Consulta de riesgo AML realizada correctamente',

                'data' =>
                    $this->mapCheck($check),
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudo realizar la consulta de riesgo AML'
            );
        }
    }

    public function result(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $check = $this->amlService
                ->consultarDetalleProveedor(
                    (int) $proveedor->id,
                    $user
                );

            return $this->responseData([
                'message' =>
                    'Consulta de detalle AML realizada correctamente',

                'data' =>
                    $this->mapCheck($check),
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudo realizar la consulta de detalle AML'
            );
        }
    }

    public function flags(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $check = $this->amlService
                ->consultarFlagsProveedor(
                    (int) $proveedor->id,
                    $user
                );

            return $this->responseData([
                'message' =>
                    'Consulta de flags AML realizada correctamente',

                'data' =>
                    $this->mapCheck($check),
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudo realizar la consulta de flags AML'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Generación y descarga del reporte AML
    |--------------------------------------------------------------------------
    */

    /**
     * Genera el reporte en Gesintel y guarda el pdfLink obtenido.
     */
    public function report(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $check = $this->amlService
                ->generarReporteProveedor(
                    (int) $proveedor->id,
                    $user
                );

            return $this->responseData([
                'message' =>
                    'Reporte AML generado correctamente',

                'data' =>
                    $this->mapCheck($check),
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudo generar el reporte AML'
            );
        }
    }

    /**
     * Descarga el último PDF generado.
     *
     * El backend consulta el pdfLink de Gesintel y agrega internamente:
     *
     * Authorization: TOKEN_GESINTEL
     *
     * El token de Gesintel nunca se entrega al frontend.
     */
    public function downloadReportPdf(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            /*
             * Valida que el proveedor exista y sea visible
             * para el usuario autenticado.
             */
            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $file = $this->amlService
                ->descargarReporteProveedor(
                    (int) $proveedor->id
                );

            $content =
                $file['content'] ?? null;

            if (
                !is_string($content) ||
                $content === ''
            ) {
                throw new \Exception(
                    'No se recibió el contenido del reporte AML'
                );
            }

            $contentType =
                $file['contentType']
                ?? 'application/pdf';

            $fileName =
                $this->sanitizeFileName(
                    $file['fileName']
                    ?? 'reporte-aml.pdf'
                );

            return response(
                $content,
                200,
                [
                    'Content-Type' =>
                        $contentType,

                    'Content-Disposition' =>
                        'inline; filename="' .
                        $fileName .
                        '"',

                    'Content-Length' =>
                        strlen($content),

                    'Cache-Control' =>
                        'private, no-store, no-cache, must-revalidate, max-age=0',

                    'Pragma' =>
                        'no-cache',

                    'Expires' =>
                        '0',

                    'X-Content-Type-Options' =>
                        'nosniff',
                ]
            );
        } catch (
            \Illuminate\Database\Eloquent\ModelNotFoundException $error
        ) {
            return $this->responseData([
                'error' => true,
                'message' =>
                    'Proveedor o reporte AML no encontrado',
            ], 404);
        } catch (\Throwable $error) {
            return $this->responseData([
                'error' => true,
                'message' => env('APP_DEBUG', false)
                    ? ($error->getMessage()
                        ?: 'No se pudo descargar el reporte AML')
                    : 'No se pudo descargar el reporte AML',
            ], 422);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Registro AMLUpdate / UBOFinder
    |--------------------------------------------------------------------------
    */

    public function registerRecord(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $record = $this->amlService
                ->registrarProveedor(
                    (int) $proveedor->id,
                    $user
                );

            return $this->responseData([
                'message' =>
                    'Proveedor registrado en AMLUpdate correctamente',

                'data' =>
                    $this->mapRecord($record),
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudo registrar el proveedor en AMLUpdate'
            );
        }
    }

    public function getRecord(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $record = $this->amlService
                ->consultarRegistroProveedor(
                    (int) $proveedor->id,
                    $user
                );

            return $this->responseData([
                'message' =>
                    'Registro AML consultado correctamente',

                'data' =>
                    $this->mapRecord($record),
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudo consultar el registro AML'
            );
        }
    }

    public function getPdfRecord(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $record = $this->amlService
                ->obtenerPdfRegistroProveedor(
                    (int) $proveedor->id,
                    $user
                );

            return $this->responseData([
                'message' =>
                    'PDF de registro AML obtenido correctamente',

                'data' =>
                    $this->mapRecord($record),
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudo obtener el PDF del registro AML'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Histórico local
    |--------------------------------------------------------------------------
    */

    public function checks(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $checks = $this->amlService
                ->obtenerChecksProveedor(
                    (int) $proveedor->id
                )
                ->map(function ($check) {
                    return $this->mapCheck(
                        $check
                    );
                })
                ->values();

            return $this->responseData([
                'data' => $checks,
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudieron obtener las consultas AML'
            );
        }
    }

    public function records(Request $request, $id)
    {
        try {
            $user = $request->auth;

            if (!$this->canUseAmlApi($user)) {
                return $this->amlForbiddenResponse();
            }

            $proveedor = $this->findProveedorVisibleOrFail(
                $id,
                $user
            );

            $records = $this->amlService
                ->obtenerRecordsProveedor(
                    (int) $proveedor->id
                )
                ->map(function ($record) {
                    return $this->mapRecord(
                        $record
                    );
                })
                ->values();

            return $this->responseData([
                'data' => $records,
            ], 200);
        } catch (\Throwable $error) {
            return $this->errorResponse(
                $error,
                'No se pudieron obtener los registros AML'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permisos
    |--------------------------------------------------------------------------
    */

    private function canUseAmlApi($user): bool
    {
        if (!$user) {
            return false;
        }

        if (
            !$this->permissions
                ->isInternalUser($user)
        ) {
            return false;
        }

        $allowedAreas = [
            'contraloria',
            'legal_crediticia',
        ];

        $area = isset($user->area)
            ? strtolower(
                trim(
                    (string) $user->area
                )
            )
            : '';

        return in_array(
            $area,
            $allowedAreas,
            true
        );
    }

    private function amlForbiddenResponse()
    {
        return $this->responseData([
            'error' => true,

            'message' =>
                'No tienes permisos para usar consultas AML. Solo Contraloría y Legal Crediticia pueden utilizar esta función.',
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | Visibilidad de proveedores
    |--------------------------------------------------------------------------
    */

    private function findProveedorVisibleOrFail(
        $id,
        $user
    ) {
        if (
            !$this->permissions
                ->isInternalUser($user)
        ) {
            abort(
                403,
                'No autorizado'
            );
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
        ])->where(
            'id',
            $id
        );

        $this->permissions
            ->applyProveedorVisibilityQuery(
                $query,
                $user
            );

        return $query->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Mapeo de respuestas
    |--------------------------------------------------------------------------
    */

    private function mapCheck($check): array
    {

        $responseJson = $this->decodeJson(
            $check->response_json
        );

        return [
            'id' =>
                (string) $check->id,

            'proveedorId' =>
                $check->proveedor_id
                    ? (string) $check->proveedor_id
                    : null,

            'endpoint' =>
                $check->endpoint,

            'rut' =>
                $check->rut,

            'nombre' =>
                $check->nombre,

            'amlStatus' =>
                $check->aml_status,

            'percent' =>
                $check->percent !== null
                    ? (float) $check->percent
                    : null,

            'pepsanColor' =>
                $check->pepsan_color,

            'kycameColor' =>
                $check->kycame_color,

            'ubocomColor' =>
                $check->ubocom_color,

            'response' =>
                $responseJson,

            'createdByUserId' =>
                $check->created_by_user_id
                    ? (string) $check->created_by_user_id
                    : null,

            'createdAt' =>
                $check->created_at,

            'updatedAt' =>
                $check->updated_at,
        ];
    }

    private function mapRecord($record): array
    {

        $responseJson = $this->decodeJson(
            $record->response_json
        );

        return [
            'id' =>
                (string) $record->id,

            'proveedorId' =>
                $record->proveedor_id
                    ? (string) $record->proveedor_id
                    : null,

            'rut' =>
                $record->rut,

            'nombre' =>
                $record->nombre,

            'category' =>
                $record->category,

            'status' =>
                $record->status,

            'extId' =>
                $record->ext_id,

            'response' =>
                $responseJson,

            'createdByUserId' =>
                $record->created_by_user_id
                    ? (string) $record->created_by_user_id
                    : null,

            'createdAt' =>
                $record->created_at,

            'updatedAt' =>
                $record->updated_at,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function decodeJson(
        ?string $json
    ) {
        if (!$json) {
            return null;
        }

        $decoded = json_decode(
            $json,
            true
        );

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : $json;
    }

    private function sanitizeFileName(
        string $fileName
    ): string {
        $sanitized = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '-',
            $fileName
        );

        if (
            !is_string($sanitized) ||
            $sanitized === ''
        ) {
            return 'reporte-aml.pdf';
        }

        if (
            strtolower(
                substr(
                    $sanitized,
                    -4
                )
            ) !== '.pdf'
        ) {
            $sanitized .= '.pdf';
        }

        return $sanitized;
    }

    private function errorResponse(
        \Throwable $error,
        string $fallbackMessage
    ) {
        $statusCode = 500;

        if (
            $error instanceof
            \Illuminate\Database\Eloquent\ModelNotFoundException
        ) {
            $statusCode = 404;
        }

        return $this->responseData([
            'error' => true,
            'message' => env('APP_DEBUG', false)
                ? ($error->getMessage() ?: $fallbackMessage)
                : $fallbackMessage,
        ], $statusCode);
    }
}
