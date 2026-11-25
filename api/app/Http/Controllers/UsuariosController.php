<?php

namespace App\Http\Controllers;

use App\Usuarios\Interface\UsersEnti;

class UsuariosController extends Controller{

    private $usuarios;

    public function __construct(UsersEnti $usuarios){
        $this->usuarios = $usuarios;
    }

    public function usuarios(){
        return $this->responseData($this->usuarios->getUsuarios(), 200);
    }

    public function usuario($email){
        return $this->responseData($this->usuarios->getUsuario($email), 200);
    }

    // public function crear(){
    //     return $this->responseData(["msg" => "Usuario Creado"], 200);
    // }
}
