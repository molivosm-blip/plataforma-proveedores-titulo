<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\ProveedorDocumento;
use App\Models\ProveedorHistorial;
use App\Models\ProveedorDetalle;
use App\Models\ProveedorWorkflowStep;
use App\Models\ProveedorValidacion;
use App\Models\ProveedorSociedadHabilitada;

class Proveedores extends Model
{
    protected $table = 'proveedores';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'razon_social',
        'rut',
        'giro',
        'tipo',
        'empresa_id',
        'estado',
        'proveedor_user_id',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    /**
     * Usuario proveedor asociado.
     *
     * proveedores.proveedor_user_id → users.id
     */
    public function usuarioProveedor()
    {
        return $this->belongsTo(
            User::class,
            'proveedor_user_id',
            'id'
        );
    }

    /**
     * Usuario PM/usuario interno que creó este proveedor.
     *
     * proveedores.created_by_user_id → .id
     */
    public function creadoPor()
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id',
            'id'
        );
    }

    /**
     * Documentos del proveedor.
     *
     * proveedor_documentos.proveedor_id → proveedores.id
     */
    public function documentos()
    {
        return $this->hasMany(
            ProveedorDocumento::class,
            'proveedor_id',
            'id'
        );
    }

    /**
     * Historial/auditoría del proveedor.
     *
     * proveedor_historial.proveedor_id → proveedores.id
     */
    public function historial()
    {
        return $this->hasMany(
            ProveedorHistorial::class,
            'proveedor_id',
            'id'
        );
    }

    /**
     * Detalle extendido del proveedor.
     *
     * proveedor_detalles.proveedor_id → proveedores.id
     */
    public function detalle()
    {
        return $this->hasOne(
            ProveedorDetalle::class,
            'proveedor_id',
            'id'
        );
    }

    /**
     * Sociedades habilitadas del proveedor.
     *
     * proveedor_sociedades_habilitadas.proveedor_id → proveedores.id
     *
     * Regla de negocio:
     * Cada sociedad seleccionada habilita automáticamente al proveedor
     * como sociedad y como compra.
     */
    public function sociedadesHabilitadas()
    {
        return $this->hasMany(
            ProveedorSociedadHabilitada::class,
            'proveedor_id',
            'id'
        );
    }

    /**
     * Etapas del workflow del proveedor.
     *
     * proveedor_workflow_steps.proveedor_id → proveedores.id
     */
    public function workflowSteps()
    {
        return $this->hasMany(
            ProveedorWorkflowStep::class,
            'proveedor_id',
            'id'
        );
    }

    /**
     * Validaciones por área del proveedor.
     *
     * proveedor_validaciones.proveedor_id → proveedores.id
     */
    public function validaciones()
    {
        return $this->hasMany(
            ProveedorValidacion::class,
            'proveedor_id',
            'id'
        );
    }
}