<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorSapEnvio extends Model
{
    protected $table = 'proveedor_sap_envios';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'estado',
        'payload_json',
        'respuesta_json',
        'numero_proveedor_sap',
        'created_by_user_id',
        'created_at',
    ];
}
