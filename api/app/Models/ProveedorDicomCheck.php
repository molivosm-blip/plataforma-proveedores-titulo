<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorDicomCheck extends Model
{
    protected $table = 'proveedor_dicom_checks';

    protected $fillable = [
        'proveedor_id',
        'endpoint',
        'segmento',
        'rut',
        'fecha_consulta',
        'request_url',
        'response_json',
        'http_code',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    public $timestamps = false;
}