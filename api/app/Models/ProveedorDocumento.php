<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Proveedores;
use App\Models\ProveedorHistorial;

class ProveedorDocumento extends Model
{
    protected $table = 'proveedor_documentos';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'nombre',

        /*
        |--------------------------------------------------------------------------
        | Metadata documental
        |--------------------------------------------------------------------------
        */
        'responsable_carga',
        'area_responsable',
        'es_prerrequisito',
        'bloquea_carga_proveedor',
        'visible_para_proveedor',
        'visible_para_internos',
        'plantilla_url',

        /*
        |--------------------------------------------------------------------------
        | Estado y archivo
        |--------------------------------------------------------------------------
        */
        'estado',
        'archivo_nombre',
        'archivo_url',
        'archivo_mime_type',

        /*
        |--------------------------------------------------------------------------
        | Trazabilidad
        |--------------------------------------------------------------------------
        */
        'uploaded_by_user_id',
        'comentario',
        'reviewed_by_user_id',
        'reviewed_at',

        /*
        |--------------------------------------------------------------------------
        | Fechas
        |--------------------------------------------------------------------------
        */
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'proveedor_id' => 'integer',

        'es_prerrequisito' => 'boolean',
        'bloquea_carga_proveedor' => 'boolean',
        'visible_para_proveedor' => 'boolean',
        'visible_para_internos' => 'boolean',

        'uploaded_by_user_id' => 'integer',
        'reviewed_by_user_id' => 'integer',

        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    /**
     * Documento pertenece a un proveedor.
     *
     * proveedor_documentos.proveedor_id → proveedores.id
     */
    public function proveedor()
    {
        return $this->belongsTo(
            Proveedores::class,
            'proveedor_id',
            'id'
        );
    }

    /**
     * Historial del proveedor asociado a este documento.
     *
     * Nota:
     * El historial está asociado al proveedor, no directamente al documento.
     */
    public function historial()
    {
        return $this->hasMany(
            ProveedorHistorial::class,
            'proveedor_id',
            'proveedor_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Indica si este documento debe ser cargado por PM/Solicitante.
     */
    public function esDocumentoPm(): bool
    {
        return $this->responsable_carga === 'pm';
    }

    /**
     * Indica si este documento debe ser cargado por proveedor.
     */
    public function esDocumentoProveedor(): bool
    {
        return $this->responsable_carga === 'proveedor';
    }

    /**
     * Indica si este documento bloquea la carga documental del proveedor.
     */
    public function bloqueaCargaProveedor(): bool
    {
        return (bool) $this->es_prerrequisito
            && (bool) $this->bloquea_carga_proveedor;
    }

    /**
     * Indica si el documento ya fue cargado.
     */
    public function estaSubido(): bool
    {
        return in_array($this->estado, ['Subido', 'Aprobado'], true);
    }

    /**
     * Indica si el documento está aprobado.
     */
    public function estaAprobado(): bool
    {
        return $this->estado === 'Aprobado';
    }
}