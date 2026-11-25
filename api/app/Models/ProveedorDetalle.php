<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Proveedores;

class ProveedorDetalle extends Model
{
    protected $table = 'proveedor_detalles';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',

        'direccion',
        'comuna',
        'region',
        'codigo_postal',
        'telefono',
        'moneda',
        'pais',

        'representante_legal',
        'representante_ci',
        'representante_direccion',
        'representante_email',
        'representante_cargo',

        'persona_contacto',
        'persona_contacto_cargo',
        'persona_contacto_telefono',
        'persona_contacto_email',

        'fecha_escritura_publica',
        'notaria',

        'plazo_pago',
        'forma_pago',
        'dias_despacho',
        'banco_pago',
        'cuenta_corriente',

        'dimension_negocio_texto',
        'tipo_proveedor_detalle',

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
}