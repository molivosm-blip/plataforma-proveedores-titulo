<?php

require_once __DIR__ . '/../vendor/autoload.php';

(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
    dirname(__DIR__)
))->bootstrap();

date_default_timezone_set(env('APP_TIMEZONE', 'America/Santiago'));

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

$app = new Laravel\Lumen\Application(
    dirname(__DIR__)
);

$app->withFacades();
$app->withEloquent();

/*
|--------------------------------------------------------------------------
| Container Bindings
|--------------------------------------------------------------------------
*/

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| Load Configuration Files
|--------------------------------------------------------------------------
*/

$app->configure('app');
$app->configure('database');
//$app->configure('sentry');

/*
|--------------------------------------------------------------------------
| Register Route Middleware
|--------------------------------------------------------------------------
| OJO: se definen UNA SOLA VEZ
*/

$app->routeMiddleware([
    'auth' => App\Http\Middleware\AuthMiddleware::class,
    'jwt'  => App\Http\Middleware\JwtMiddleware::class,
    'role' => App\Http\Middleware\RoleMiddleware::class,
]);
/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
*/

//$app->register(Yajra\Oci8\Oci8ServiceProvider::class);
$app->register(\Anik\Form\FormRequestServiceProvider::class);
//$app->register(Sentry\Laravel\ServiceProvider::class);

/*
|--------------------------------------------------------------------------
| Interface Bindings
|--------------------------------------------------------------------------
*/

$app->bind(
    App\Usuarios\Interface\UsersInterface::class,
    App\Usuarios\Repository\UsersRepository::class
);

$app->bind(
    App\Usuarios\Interface\UsersEnti::class,
    App\Usuarios\Usuario::class
);

$app->bind(
    App\Proveedores\Interfaces\ProveedoresInterface::class,
    App\Proveedores\Proveedores::class
);

$app->bind(
    App\Proveedores\Interfaces\ProveedoresRepo::class,
    App\Proveedores\Repository\ProveedoresRepository::class
);

/*
|--------------------------------------------------------------------------
| Load Routes
|--------------------------------------------------------------------------
*/

$app->router->group([
    'namespace' => 'App\Http\Controllers',
], function ($router) {
    require __DIR__ . '/../routes/api.php';
});

return $app;
