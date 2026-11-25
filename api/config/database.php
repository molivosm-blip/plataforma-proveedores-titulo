<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        /*
        |--------------------------------------------------------------------------
        | ✅ CONEXIÓN POR DEFECTO (OBLIGATORIA PARA LUMEN)
        |--------------------------------------------------------------------------
        */
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_DATABASE'),
            'username'  => env('DB_USERNAME'),
            'password'  => env('DB_PASSWORD'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => null,
            'options'   => extension_loaded('pdo_mysql') ? [
                PDO::ATTR_EMULATE_PREPARES => true,
            ] : [],
        ],

        /*
        |--------------------------------------------------------------------------
        | ✅ CONEXIÓN SECUNDARIA (OPCIONAL)
        | Usa esta solo si un modelo la especifica explícitamente
        |--------------------------------------------------------------------------
        */
        'proveedores' => [
            'driver'    => 'mysql',
            'host'      => env('CERBERUS_HOST'),
            'port'      => '3306',
            'database'  => env('PROVEEDORES_DMC_DATABASE'),
            'username'  => env('CERBERUS_USERNAME'),
            'password'  => env('CERBERUS_PASSWORD'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
            'engine'    => null,
            'options'   => extension_loaded('pdo_mysql') ? [
                PDO::ATTR_EMULATE_PREPARES => true,
            ] : [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */

    'migrations' => 'migrations',
];
