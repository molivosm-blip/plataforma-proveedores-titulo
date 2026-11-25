<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorAmlCheck extends Model
{
    protected $table = 'proveedor_aml_checks';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'endpoint',
        'rut',
        'nombre',
        'aml_status',
        'percent',
        'pepsan_color',
        'kycame_color',
        'ubocom_color',
        'pdf_link',
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