<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProveedorDeclaracion extends Model
{
    protected $table = 'proveedor_declaraciones';
    protected $primaryKey = 'id';

    public $timestamps = true;

    public const TIPO_CONFLICTO = 'conflicto_interes';
    public const TIPO_PEP = 'relacionados_pep';

    /*
     * Nombre del documento del catálogo que se reemplaza por cada declaración digital.
     */
    public const DOCUMENTOS = [
        self::TIPO_CONFLICTO => 'Declaración Conflicto de Interés Solicitante',
        self::TIPO_PEP       => 'Declaración Relacionados y PEP',
    ];

    protected $fillable = [
        'proveedor_id',
        'documento_id',
        'tipo',
        'respuestas_json',
        'declara_relacionados',
        'declara_pep',
        'declarante_nombre',
        'declarante_rut',
        'declarante_cargo',
        'declarante_email',
        'firmado_por_user_id',
        'firmado_at',
        'firma_ip',
        'firma_user_agent',
        'firma_hash',
    ];

    protected $casts = [
        'declara_relacionados' => 'boolean',
        'declara_pep'          => 'boolean',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedores::class, 'proveedor_id', 'id');
    }

    public function respuestas(): array
    {
        $data = json_decode((string) $this->respuestas_json, true);

        return is_array($data) ? $data : [];
    }
}
