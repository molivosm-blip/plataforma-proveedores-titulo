<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Proveedores;
use App\Models\User;

class ProveedorValidacion extends Model
{
    protected $table = 'proveedor_validaciones';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'area',
        'area_name',
        'status',
        'validated_by_user_id',
        'validated_at',
        'comments',
        'created_at',
        'updated_at',
    ];

    public function proveedor()
    {
        return $this->belongsTo(
            Proveedores::class,
            'proveedor_id',
            'id'
        );
    }

    public function validadoPor()
    {
        return $this->belongsTo(
            User::class,
            'validated_by_user_id',
            'id'
        );
    }
}