<?php

namespace App\Proveedores;

use App\Proveedores\Interfaces\ProveedoresInterface;
use App\Proveedores\Interfaces\ProveedoresRepo;

class Proveedores implements ProveedoresInterface
{
    private $proveedoresRepo;

    public function __construct(ProveedoresRepo $proveedoresRepo)
    {
        $this->proveedoresRepo = $proveedoresRepo;
    }

    public function crear(array $data)
    {
        return $this->proveedoresRepo->crear($data);
    }
}