<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    protected $table = 'notificaciones';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'proveedor_id',
        'tipo',
        'referencia',
        'titulo',
        'mensaje',
        'email_enviado',
        'leida_at',
        'created_at',
    ];

    protected $casts = [
        'email_enviado' => 'boolean',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
