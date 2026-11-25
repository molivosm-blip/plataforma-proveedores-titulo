<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
        /*
         * El portal usa autenticación JWT mediante JwtMiddleware.
         *
         * No se usa api_token, porque la tabla users no tiene esa columna.
         * El usuario autenticado se inyecta en:
         *
         * $request->auth
         *
         * desde App\Http\Middleware\JwtMiddleware.
         */
    }
}