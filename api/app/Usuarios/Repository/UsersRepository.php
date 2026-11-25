<?php

namespace App\Usuarios\Repository;

use App\Models\User;
use App\Usuarios\Interface\UsersInterface;

class UsersRepository implements UsersInterface
{
    private $model;

    public function __construct(User $model)
    {
        $this->model = $model;
    }

    public function getUsuarios()
    {
        return $this->model
            ->select(
                'id',
                'email',
                'display_name',
                'role',
                'id_empresa',
                'area',
                'active'
            )
            ->with('proveedor')
            ->get();
    }

    public function getUsuario($email)
    {
        return $this->model
            ->select(
                'id',
                'email',
                'display_name',
                'role',
                'id_empresa',
                'area',
                'active'
            )
            ->with('proveedor')
            ->where('email', $email)
            ->first();
    }
}