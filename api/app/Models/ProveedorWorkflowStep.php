<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Proveedores;
use App\Models\User;

class ProveedorWorkflowStep extends Model
{
    protected $table = 'proveedor_workflow_steps';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'step_key',
        'step_name',
        'area',
        'status',
        'sort_order',
        'assigned_user_id',
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

    public function asignadoA()
    {
        return $this->belongsTo(
            User::class,
            'assigned_user_id',
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