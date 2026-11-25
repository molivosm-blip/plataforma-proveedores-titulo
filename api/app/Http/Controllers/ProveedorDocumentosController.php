<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ProveedorDocumento;
use App\Models\ProveedorHistorial;
use App\Services\AreaPermissionService;
use App\Services\DocumentoStorageService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use App\Services\ProveedorDocumentosService;
use App\Services\ProveedorEstadoService;
use App\Services\NotificacionService;
use App\Models\ProveedorDeclaracion;

class ProveedorDocumentosController extends Controller
{
    protected $permissions;
    protected $documentosService;
    protected $estadoService;
    protected $notificaciones;
    protected $storage;

    public function __construct(
        AreaPermissionService $permissions,
        ProveedorDocumentosService $documentosService,
        ProveedorEstadoService $estadoService,
        NotificacionService $notificaciones,
        DocumentoStorageService $storage
    ) {
        $this->permissions = $permissions;
        $this->documentosService = $documentosService;
        $this->estadoService = $estadoService;
        $this->notificaciones = $notificaciones;
        $this->storage = $storage;
    }

    /**
     * Subida simple / creación de documento por proveedor.
     *
     * Nota:
     * Esta ruta permite crear un documento nuevo con archivo_url.
     * Para subir archivo real a un documento existente usar upload().
     */
    public function store(Request $request)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->isProveedor($user)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Solo proveedores',
                ], 403);
            }

            if (!$user->proveedor) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Sin proveedor asociado',
                ], 422);
            }

            if (!$this->documentosService->proveedorPuedeSubirDocumentos($user->proveedor->id)) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'La carga de documentos está bloqueada. El PM/Solicitante debe cargar primero los documentos requeridos.',
                    'canUploadDocuments' => false,
                    'pmPrerequisitesPending' => $this->documentosService->obtenerPrerequisitosPendientes($user->proveedor->id),
                ], 403);
            }

            /*
             * Seguridad: este endpoint ya no acepta una ruta de archivo enviada
             * por el cliente (permitía apuntar a archivos de otros proveedores).
             * Los archivos se suben solo con POST /v1/documentos/{id}/upload.
             */
            if ($request->has('archivo_url') && $request->input('archivo_url') !== null) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'No se acepta una ruta de archivo. Suba el archivo con la opción de carga del documento.',
                ], 422);
            }

            $this->validate($request, [
                'nombre'            => 'required|string',
                'archivo_url'       => 'nullable|string',
                'archivo_nombre'    => 'nullable|string',
                'archivo_mime_type' => 'nullable|string',
                'comentario'        => 'nullable|string',
            ]);

            if ($request->input('nombre') === 'Ficha de incorporación proveedor') {
                return $this->responseData([
                    'error' => true,
                    'message' => 'La ficha de incorporación se genera automáticamente. No requiere carga manual.',
                ], 422);
            }

            DB::beginTransaction();
            $transactionStarted = true;

            $doc = ProveedorDocumento::create([
                'proveedor_id'            => $user->proveedor->id,
                'nombre'                  => $request->input('nombre'),
                'responsable_carga'       => 'proveedor',
                'area_responsable'        => null,
                'es_prerrequisito'        => 0,
                'bloquea_carga_proveedor' => 0,
                'visible_para_proveedor'  => 1,
                'visible_para_internos'   => 1,
                'plantilla_url'           => null,
                'archivo_nombre'          => $request->input('archivo_nombre'),
                'archivo_url'             => null,
                'archivo_mime_type'       => $request->input('archivo_mime_type'),
                'uploaded_by_user_id'     => $user->id,
                'estado'                  => 'Pendiente',
                'comentario'              => $request->input('comentario'),
                'created_at'              => date('Y-m-d H:i:s'),
                'updated_at'              => date('Y-m-d H:i:s'),
            ]);

            ProveedorHistorial::create([
                'proveedor_id'  => $user->proveedor->id,
                'accion'        => 'Documento "' . $doc->nombre . '" subido',
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $nuevoEstadoProveedor = $this->estadoService
                ->recalcularEstadoProveedor($user->proveedor->id);

            ProveedorHistorial::create([
                'proveedor_id'  => $user->proveedor->id,
                'accion'        => 'Estado general del proveedor recalculado a ' . $nuevoEstadoProveedor,
                'realizado_por' => 'Sistema',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            DB::commit();

            return $this->responseData([
                'message' => 'Documento creado',
                'data'    => $this->mapDocumento($doc),
            ], 201);

        } catch (\Exception $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

            return $this->responseData([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Documentos propios del proveedor autenticado.
     */
    public function mine(Request $request)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->isProveedor($user)) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            if (!$user->proveedor) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'Sin proveedor asociado',
                ], 422);
            }

            $docs = ProveedorDocumento::where('proveedor_id', $user->proveedor->id)
                ->where('visible_para_proveedor', 1)
                ->where(function ($query) {
                    $query->whereNull('responsable_carga')
                        ->orWhere('responsable_carga', '<>', 'pm');
                })
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($d) {
                    return $this->mapDocumento($d);
                });

            return $this->responseData([
                'canUploadDocuments' => $this->documentosService->proveedorPuedeSubirDocumentos($user->proveedor->id),
                'pmPrerequisitesPending' => $this->documentosService->obtenerPrerequisitosPendientes($user->proveedor->id),
                'data' => $docs,
            ], 200);

        } catch (\Exception $e) {
            return $this->responseData([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Interno: ver documentos de proveedores según visibilidad del usuario.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $query = ProveedorDocumento::with('proveedor')
                ->where('visible_para_internos', 1)
                ->whereHas('proveedor', function ($q) use ($user) {
                    $this->permissions->applyProveedorVisibilityQuery($q, $user);
                })
                ->orderBy('id', 'desc');

            $docs = $query->get()->map(function ($d) {
                return $this->mapDocumentoConProveedor($d);
            });

            return $this->responseData([
                'data' => $docs,
            ], 200);

        } catch (\Exception $e) {
            return $this->responseData([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar estado documental.
     *
     * Estados permitidos:
     * - Pendiente
     * - Subido
     * - Observado
     * - Aprobado
     */
    public function updateEstado(Request $request, $id)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (!$this->permissions->isInternalUser($user)) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $this->validate($request, [
                'estado'     => 'required|in:Pendiente,Subido,Observado,Aprobado',
                'comentario' => 'nullable|string',
            ]);

            $doc = ProveedorDocumento::with('proveedor')->findOrFail($id);

            if (!$this->canReviewDocumentoConReglas($user, $doc)) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'Sin permisos para revisar este documento',
                ], 403);
            }

            DB::beginTransaction();
            $transactionStarted = true;

            $estadoAnterior = $doc->estado;

            $doc->estado = $request->input('estado');
            $doc->comentario = $request->input('comentario');
            $doc->reviewed_by_user_id = $user->id;
            $doc->reviewed_at = date('Y-m-d H:i:s');
            $doc->updated_at = date('Y-m-d H:i:s');
            $doc->save();

            ProveedorHistorial::create([
                'proveedor_id'  => $doc->proveedor_id,
                'accion'        => 'Documento "' . $doc->nombre . '" cambió de ' . $estadoAnterior . ' a ' . $doc->estado,
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $nuevoEstadoProveedor = $this->estadoService
                ->recalcularEstadoProveedor($doc->proveedor_id);

            ProveedorHistorial::create([
                'proveedor_id'  => $doc->proveedor_id,
                'accion'        => 'Estado general del proveedor recalculado a ' . $nuevoEstadoProveedor,
                'realizado_por' => 'Sistema',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            DB::commit();
            $transactionStarted = false;

            /*
             * Un documento observado vuelve a quien debe corregirlo.
             */
            if ($doc->estado === 'Observado' && $estadoAnterior !== 'Observado' && $doc->proveedor) {
                $this->notificarSinBloquear(function () use ($doc) {
                    $titulo = 'Documento observado: ' . $doc->nombre;
                    $mensaje = 'El documento "' . $doc->nombre . '" de ' . $doc->proveedor->razon_social
                        . ' fue observado.' . ($doc->comentario ? ' Comentario: ' . $doc->comentario : '');

                    if ($doc->responsable_carga === 'proveedor') {
                        $this->notificaciones->notificarProveedor($doc->proveedor, 'documento_observado', $titulo, $mensaje, 'documento:' . $doc->id);
                    } else {
                        $this->notificaciones->notificarSolicitante($doc->proveedor, 'documento_observado', $titulo, $mensaje, 'documento:' . $doc->id);
                    }
                });
            }

            $doc = ProveedorDocumento::with('proveedor')->find($id);

            return $this->responseData([
                'message' => 'Documento actualizado',
                'data'    => $this->mapDocumentoConProveedor($doc),
            ], 200);

        } catch (\Exception $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

            return $this->responseData([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alias legacy.
     */
    public function review(Request $request, $id)
    {
        return $this->updateEstado($request, $id);
    }

    /**
     * Upload real de archivo a un documento existente.
     *
     * Acepta:
     * - archivo
     * - file
     */
    public function upload(Request $request, $id)
    {
        $transactionStarted = false;

        try {
            $user = $request->auth;

            if (
                !$this->permissions->isInternalUser($user) &&
                !$this->permissions->isProveedor($user)
            ) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'No autorizado',
                ], 403);
            }

            $fileInput = null;

            if ($request->hasFile('archivo')) {
                $fileInput = 'archivo';
            }

            if ($request->hasFile('file')) {
                $fileInput = 'file';
            }

            if (!$fileInput) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'Archivo requerido',
                ], 422);
            }

            $file = $request->file($fileInput);
            $validationError = $this->validateArchivo($file);

            if ($validationError) {
                return $this->responseData([
                    'error'   => true,
                    'message' => $validationError,
                ], 422);
            }

            $doc = ProveedorDocumento::with('proveedor')->findOrFail($id);

            if (!$this->canUploadDocumentoConReglas($user, $doc)) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'Sin permisos para subir este documento',
                ], 403);
            }

            if (in_array($doc->nombre, ProveedorDeclaracion::DOCUMENTOS, true)) {
                return $this->responseData([
                    'error'   => true,
                    'message' => 'Esta declaración se completa y firma en el formulario digital del portal.',
                ], 422);
            }

            if ($this->permissions->isProveedor($user)) {
                if (!$this->documentosService->proveedorPuedeSubirDocumentos($doc->proveedor_id)) {
                    return $this->responseData([
                        'error' => true,
                        'message' => 'La carga de documentos está bloqueada. El PM/Solicitante debe cargar primero los documentos requeridos.',
                        'canUploadDocuments' => false,
                        'pmPrerequisitesPending' => $this->documentosService->obtenerPrerequisitosPendientes($doc->proveedor_id),
                    ], 403);
                }
            }

            DB::beginTransaction();
            $transactionStarted = true;

            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();

            $name = 'doc_' . $doc->id . '_' . time() . '.' . $extension;

            $archivoUrl = $this->storage->guardarSubida($file, 'proveedor_documentos', $name);

            $doc->archivo_nombre = $originalName;
            $doc->archivo_url = $archivoUrl;
            $doc->archivo_mime_type = $mimeType;
            $doc->estado = 'Subido';
            $doc->uploaded_by_user_id = $user->id;
            $doc->updated_at = date('Y-m-d H:i:s');
            $doc->save();

            ProveedorHistorial::create([
                'proveedor_id'  => $doc->proveedor_id,
                'accion'        => 'Archivo subido para documento "' . $doc->nombre . '"',
                'realizado_por' => $user->display_name ?: $user->email,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            $nuevoEstadoProveedor = $this->estadoService
                ->recalcularEstadoProveedor($doc->proveedor_id);

            ProveedorHistorial::create([
                'proveedor_id'  => $doc->proveedor_id,
                'accion'        => 'Estado general del proveedor recalculado a ' . $nuevoEstadoProveedor,
                'realizado_por' => 'Sistema',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            DB::commit();
            $transactionStarted = false;

            if ($doc->area_responsable && $doc->proveedor) {
                $this->notificarSinBloquear(function () use ($doc) {
                    $this->notificaciones->notificarArea(
                        $doc->area_responsable,
                        $doc->proveedor,
                        'documento_subido',
                        'Documento por revisar: ' . $doc->nombre,
                        'Se cargó el documento "' . $doc->nombre . '" de ' . $doc->proveedor->razon_social . '.',
                        'documento:' . $doc->id
                    );
                });
            }

            $doc = ProveedorDocumento::with('proveedor')->find($id);

            return $this->responseData([
                'message' => 'Archivo subido',
                'data'    => $this->mapDocumentoConProveedor($doc),
            ], 200);

        } catch (\Exception $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

            return $this->responseData([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Entrega el archivo de un documento solo a quien tenga sesión y permiso.
     *
     * GET /v1/documentos/{id}/archivo
     *
     * Reemplaza el acceso por URL directa a /uploads/...: los archivos están
     * fuera de public/ y esta es la única forma de leerlos.
     */
    public function descargar(Request $request, $id)
    {
        $user = $request->auth;

        $doc = ProveedorDocumento::with('proveedor')->find($id);

        // Misma respuesta si no existe o si no tiene permiso, para no revelar ids.
        if (!$doc || !$this->permissions->canViewDocumento($user, $doc)) {
            return $this->responseData([
                'error'   => true,
                'message' => 'Documento no disponible',
            ], 404);
        }

        $ruta = $this->storage->resolver($doc->archivo_url);

        if (!$ruta) {
            return $this->responseData([
                'error'   => true,
                'message' => 'El documento no tiene archivo cargado',
            ], 404);
        }

        $mime = $this->storage->mimePorExtension($ruta);
        $nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($doc->archivo_nombre ?: basename($ruta)));

        $response = new BinaryFileResponse($ruta, 200, [
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $nombre);

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Reglas de permisos documentales
    |--------------------------------------------------------------------------
    */

    /**
     * Un error al notificar no debe revertir la operación ya guardada.
     */
    private function notificarSinBloquear(callable $accion): void
    {
        try {
            $accion();
        } catch (\Throwable $e) {
            error_log('No se pudo enviar la notificación: ' . $e->getMessage());
        }
    }

    private function canUploadDocumentoConReglas($user, ProveedorDocumento $doc): bool
    {
        if ($doc->nombre === 'Ficha de incorporación proveedor') {
            return false;
        }

        if (!$user || !$doc || !$doc->proveedor) {
            return false;
        }

        if ($this->permissions->isProveedor($user)) {
            if ((int) $doc->proveedor->proveedor_user_id !== (int) $user->id) {
                return false;
            }

            if ($doc->responsable_carga !== 'proveedor') {
                return false;
            }

            if ((int) $doc->visible_para_proveedor !== 1) {
                return false;
            }

            return true;
        }

        if (!$this->permissions->isInternalUser($user)) {
            return false;
        }

        if ($this->permissions->isAdminGlobal($user)) {
            return true;
        }

        if ($user->role === 'pm_user') {
            return $doc->responsable_carga === 'pm'
                || $doc->area_responsable === 'pm';
        }

        if (in_array($user->role, ['admin_area', 'usuario_area'], true)) {
            if (!$user->area) {
                return false;
            }

            return $doc->area_responsable === $user->area;
        }

        return false;
    }

    private function canReviewDocumentoConReglas($user, ProveedorDocumento $doc): bool
    {
        if (!$user || !$doc || !$doc->proveedor) {
            return false;
        }

        if (!$this->permissions->isInternalUser($user)) {
            return false;
        }

        if ($this->permissions->isAdminGlobal($user)) {
            return true;
        }

        if ($user->role === 'pm_user') {
            return $doc->area_responsable === 'pm'
                || $doc->responsable_carga === 'pm';
        }

        if (in_array($user->role, ['admin_area', 'usuario_area'], true)) {
            if (!$user->area) {
                return false;
            }

            return $doc->area_responsable === $user->area;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function validateArchivo($file): ?string
    {
        $allowedMimeTypes = [
            'application/pdf',
            'image/png',
            'image/jpeg',
        ];

        $allowedExtensions = [
            'pdf',
            'png',
            'jpg',
            'jpeg',
        ];

        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        if (!in_array($mimeType, $allowedMimeTypes, true) || !in_array($extension, $allowedExtensions, true)) {
            return 'Formato no permitido. Solo se aceptan PDF, PNG, JPG o JPEG.';
        }

        $maxBytes = 5 * 1024 * 1024;

        if ($file->getSize() > $maxBytes) {
            return 'El archivo es demasiado grande. Máximo permitido: 5MB.';
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Mappers
    |--------------------------------------------------------------------------
    */

    private function mapDocumento($d)
    {
        return [
            'id'              => (string) $d->id,
            'proveedorId'     => $d->proveedor_id ? (string) $d->proveedor_id : null,
            'nombre'          => $d->nombre,
            'estado'          => $d->estado,

            'responsableCarga'       => $d->responsable_carga,
            'areaResponsable'        => $d->area_responsable,
            'esPrerrequisito'        => (bool) $d->es_prerrequisito,
            'bloqueaCargaProveedor'  => (bool) $d->bloquea_carga_proveedor,
            'visibleParaProveedor'   => (bool) $d->visible_para_proveedor,
            'visibleParaInternos'    => (bool) $d->visible_para_internos,
            'plantillaUrl'           => $d->plantilla_url,

            'archivoNombre'   => $d->archivo_nombre,
            'archivoUrl'      => $d->archivo_url,
            'archivoMimeType' => $d->archivo_mime_type,

            'uploadedByUserId' => $d->uploaded_by_user_id
                ? (string) $d->uploaded_by_user_id
                : null,

            'comentario' => $d->comentario,

            'reviewedByUserId' => $d->reviewed_by_user_id
                ? (string) $d->reviewed_by_user_id
                : null,

            'reviewedAt' => $d->reviewed_at,

            'createdAt' => $d->created_at,
            'updatedAt' => $d->updated_at,
        ];
    }

    private function mapDocumentoConProveedor($d)
    {
        return [
            'documento' => $this->mapDocumento($d),
            'proveedor' => $d->proveedor ? [
                'id'          => (string) $d->proveedor->id,
                'razonSocial' => $d->proveedor->razon_social,
                'rut'         => $d->proveedor->rut,
                'estado'      => $d->proveedor->estado,
                'empresaId'   => $d->proveedor->empresa_id,
            ] : null,
        ];
    }
}