<?php

namespace App\Usuarios\Interface;

interface UsersInterface{
    public function getUsuarios();
    public function getUsuario($email);
}
