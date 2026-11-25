<?php

namespace App\Usuarios;

use App\Usuarios\Interface\UsersEnti;
use App\Usuarios\Interface\UsersInterface;

class Usuario implements UsersEnti{

    private $users;

    public function __construct(UsersInterface $users){
        $this->users = $users;
    }

    public function getUsuarios(){
        return $this->users->getUsuarios();
    }

    public function getUsuario($email){
        return $this->users->getUsuario($email);
    }

}
