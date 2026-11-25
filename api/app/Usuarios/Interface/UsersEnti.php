<?php

namespace App\Usuarios\Interface;

interface UsersEnti{
    public function getUsuarios();
    public function getUsuario($email);
}
