<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorHistorial extends Model
{
    protected $table = 'proveedor_historial';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'accion',
        'realizado_por',
        'created_at',
    ];

    /*
     * Relación: historial pertenece a un proveedor
     */
    public function proveedor()
    {
        return $this->belongsTo(
            Proveedores::class,
            'proveedor_id',
            'id'
        );
    }
}