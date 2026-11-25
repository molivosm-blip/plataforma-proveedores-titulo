<?php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Se carga primero la aplicación para disponer de las variables del archivo
| .env antes de configurar la política CORS.
|
*/
$app = require __DIR__ . '/../bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| CORS para el frontend local
|--------------------------------------------------------------------------
*/
$allowedOrigin = rtrim(
    (string) env('FRONTEND_URL', 'http://localhost:5173'),
    '/'
);

$requestOrigin = isset($_SERVER['HTTP_ORIGIN'])
    ? rtrim((string) $_SERVER['HTTP_ORIGIN'], '/')
    : null;

if ($requestOrigin !== null && hash_equals($allowedOrigin, $requestOrigin)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 86400');

/*
|--------------------------------------------------------------------------
| Preflight requests
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit();
}

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
*/
$app->run();
