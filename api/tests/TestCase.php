<?php

namespace Tests;

use Laravel\Lumen\Testing\TestCase as BaseTestCase;

/*
 * Dentro del espacio de nombres Tests, putenv('CLAVE=valor') llama a esta función
 * en lugar de la nativa: además de la variable de entorno, fija $_ENV y $_SERVER.
 * Así una prueba no depende de lo que el .env o el sistema ya tengan definido
 * (por ejemplo APP_DEBUG=true o MAIL_MODO=smtp en el equipo del desarrollador).
 */
function putenv(string $setting): bool
{
    $partes = explode('=', $setting, 2);

    if (count($partes) === 2) {
        $_ENV[$partes[0]] = $partes[1];
        $_SERVER[$partes[0]] = $partes[1];
    }

    return \putenv($setting);
}

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application.
     *
     * @return \Laravel\Lumen\Application
     */
    public function createApplication()
    {
        return require __DIR__.'/../bootstrap/app.php';
    }
}
