<?php

namespace App\Exceptions;

/*
| Se lanza cuando una cuenta está bloqueada temporalmente por exceso de
| intentos fallidos de inicio de sesión. El controlador la responde con 429.
*/
class LoginBloqueadoException extends \Exception
{
}
