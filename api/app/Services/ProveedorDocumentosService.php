<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProveedorDocumentosService
{
    /*
    |--------------------------------------------------------------------------
    | Crear documentos desde catálogo completo
    |--------------------------------------------------------------------------
    |
    | Método legacy. Se mantiene por compatibilidad.
    | En el nuevo flujo idealmente usaremos:
    | - crearDocumentosSolicitante()
    | - crearDocumentosProveedor()
    |
    */
    public function crearDocumentosDesdeCatalogo($proveedorId, $tipoProveedorDetalle)
    {
        $documentos = DB::table('documento_requisitos')
            ->where('active', 1)
            ->where(function ($query) use ($tipoProveedorDetalle) {
                $query->whereNull('tipo_proveedor_detalle')
                    ->orWhere('tipo_proveedor_detalle', $tipoProveedorDetalle);
            })
            ->orderBy('sort_order')
            ->get();

        foreach ($documentos as $documento) {
            $this->insertDocumentoSiNoExiste($proveedorId, $documento);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Crear solo documentos del solicitante / PM
    |--------------------------------------------------------------------------
    |
    | Se ejecuta cuando se crea una solicitud interna preliminar.
    | Estos documentos deben cargarse antes de invitar al proveedor.
    |
    */
    public function crearDocumentosSolicitante($proveedorId)
    {
        $documentos = DB::table('documento_requisitos')
            ->where('active', 1)
            ->where('responsable_carga', 'pm')
            ->where('es_prerrequisito', 1)
            ->where('bloquea_carga_proveedor', 1)
            ->orderBy('sort_order')
            ->get();

        foreach ($documentos as $documento) {
            $this->insertDocumentoSiNoExiste($proveedorId, $documento);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Crear documentos que debe completar/cargar el proveedor
    |--------------------------------------------------------------------------
    |
    | Se ejecuta al activar la invitación.
    | No vuelve a crear documentos PM.
    |
    */
    public function crearDocumentosProveedor($proveedorId, $tipoProveedorDetalle)
    {
        $documentos = DB::table('documento_requisitos')
            ->where('active', 1)
            ->where(function ($query) use ($tipoProveedorDetalle) {
                $query->whereNull('tipo_proveedor_detalle')
                    ->orWhere('tipo_proveedor_detalle', $tipoProveedorDetalle);
            })
            ->where(function ($query) {
                $query->where('responsable_carga', '<>', 'pm')
                    ->orWhereNull('responsable_carga');
            })
            ->orderBy('sort_order')
            ->get();

        foreach ($documentos as $documento) {
            $this->insertDocumentoSiNoExiste($proveedorId, $documento);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validar si proveedor puede subir documentos
    |--------------------------------------------------------------------------
    |
    | El proveedor solo puede subir documentos cuando los prerrequisitos
    | del solicitante/PM están en Subido o Aprobado.
    |
    */
    public function proveedorPuedeSubirDocumentos($proveedorId)
    {
        return $this->solicitanteTienePrerequisitosCompletos($proveedorId);
    }

    /*
    |--------------------------------------------------------------------------
    | Validar si se puede invitar proveedor
    |--------------------------------------------------------------------------
    |
    | Nuevo flujo:
    | PM/Solicitante solo puede invitar cuando sus 2 documentos obligatorios
    | ya están cargados.
    |
    */
    public function puedeInvitarProveedor($proveedorId): bool
    {
        return $this->solicitanteTienePrerequisitosCompletos($proveedorId);
    }

    /*
    |--------------------------------------------------------------------------
    | Verificar prerrequisitos del solicitante
    |--------------------------------------------------------------------------
    */
    public function solicitanteTienePrerequisitosCompletos($proveedorId): bool
    {
        $pendientes = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('responsable_carga', 'pm')
            ->where('es_prerrequisito', 1)
            ->where('bloquea_carga_proveedor', 1)
            ->whereNotIn('estado', ['Subido', 'Aprobado'])
            ->count();

        return $pendientes === 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Obtener prerrequisitos pendientes del solicitante
    |--------------------------------------------------------------------------
    */
    public function obtenerPrerequisitosPendientes($proveedorId)
    {
        return DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('responsable_carga', 'pm')
            ->where('es_prerrequisito', 1)
            ->where('bloquea_carga_proveedor', 1)
            ->whereNotIn('estado', ['Subido', 'Aprobado'])
            ->select('id', 'nombre', 'estado')
            ->orderBy('id')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Obtener prerrequisitos del solicitante
    |--------------------------------------------------------------------------
    */
    public function obtenerPrerequisitosSolicitante($proveedorId)
    {
        return DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('responsable_carga', 'pm')
            ->where('es_prerrequisito', 1)
            ->where('bloquea_carga_proveedor', 1)
            ->select(
                'id',
                'nombre',
                'estado',
                'archivo_nombre',
                'archivo_url',
                'archivo_mime_type',
                'comentario'
            )
            ->orderBy('id')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Insert seguro para evitar duplicados
    |--------------------------------------------------------------------------
    */
    private function insertDocumentoSiNoExiste($proveedorId, $documento): void
    {
        $existe = DB::table('proveedor_documentos')
            ->where('proveedor_id', $proveedorId)
            ->where('nombre', $documento->nombre)
            ->exists();

        if ($existe) {
            return;
        }

        DB::table('proveedor_documentos')->insert([
            'proveedor_id' => $proveedorId,
            'nombre' => $documento->nombre,

            'responsable_carga' => $documento->responsable_carga,
            'area_responsable' => $documento->area_responsable,
            'es_prerrequisito' => $documento->es_prerrequisito,
            'bloquea_carga_proveedor' => $documento->bloquea_carga_proveedor,
            'visible_para_proveedor' => $documento->visible_para_proveedor,
            'visible_para_internos' => $documento->visible_para_internos,
            'plantilla_url' => $documento->plantilla_url,

            'estado' => 'Pendiente',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}