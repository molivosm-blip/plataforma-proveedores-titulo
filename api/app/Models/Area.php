<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $table = 'areas';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'active',
        'created_at',
        'updated_at',
    ];
}
