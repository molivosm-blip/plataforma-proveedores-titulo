<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Proveedores;

class ProveedorSociedadHabilitada extends Model
{
    protected $table = 'proveedor_sociedades_habilitadas';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'sociedad_nombre',
        'created_at',
    ];

    public function proveedor()
    {
        return $this->belongsTo(
            Proveedores::class,
            'proveedor_id',
            'id'
        );
    }
}