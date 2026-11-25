<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorAmlRecord extends Model
{
    protected $table = 'proveedor_aml_records';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'rut',
        'nombre',
        'category',
        'status',
        'ext_id',
        'request_json',
        'response_json',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedores::class, 'proveedor_id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}