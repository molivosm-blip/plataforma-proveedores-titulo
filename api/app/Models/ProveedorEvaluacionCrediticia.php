<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorEvaluacionCrediticia extends Model
{
    protected $table = 'proveedor_evaluaciones_crediticias';
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'resultado',
        'reglas_json',
        'fecha_inicio_actividades',
        'dicom_resultado',
        'observaciones',
        'origen',
        'informe_documento_id',
        'created_by_user_id',
        'created_at',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedores::class, 'proveedor_id', 'id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    public function reglas(): array
    {
        $data = json_decode((string) $this->reglas_json, true);

        return is_array($data) ? $data : [];
    }
}
