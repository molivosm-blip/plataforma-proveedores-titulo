<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class ProveedorInvitation extends Model
{
    protected $table = 'proveedor_invitations';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'email',
        'token',
        'status',
        'created_by_user_id',
        'created_at',
        'used_at',
        'revoked_at',
    ];

    /**
     * Usuario PM que creó la invitación.
     */
    public function creadoPor()
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id',
            'id'
        );
    }
}