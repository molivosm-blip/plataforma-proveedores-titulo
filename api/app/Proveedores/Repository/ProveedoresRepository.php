<?php

namespace App\Proveedores\Repository;

use App\Models\Proveedores;
use App\Proveedores\Interfaces\ProveedoresRepo;

class ProveedoresRepository implements ProveedoresRepo
{
    private $proveedores;

    public function __construct(Proveedores $proveedores)
    {
        $this->proveedores = $proveedores;
    }

    public function crear(array $data)
    {
        $proveedor = new Proveedores();

        $proveedor->razon_social = $data['razon_social'];
        $proveedor->rut = $data['rut'];
        $proveedor->giro = $data['giro'];
        $proveedor->tipo = $data['tipo'];
        $proveedor->empresa_id = $data['empresa_id'];

        /*
         * Estado válido según enum de BD:
         * 'En revisión', 'Observado', 'Rechazado', 'Aprobado'
         */
        $proveedor->estado = 'En revisión';

        /*
         * Campos opcionales, si el flujo que use este repositorio los entrega.
         */
        if (isset($data['proveedor_user_id'])) {
            $proveedor->proveedor_user_id = $data['proveedor_user_id'];
        }

        if (isset($data['created_by_user_id'])) {
            $proveedor->created_by_user_id = $data['created_by_user_id'];
        }

        $proveedor->created_at = date('Y-m-d H:i:s');

        $proveedor->save();

        return $proveedor;
    }
}