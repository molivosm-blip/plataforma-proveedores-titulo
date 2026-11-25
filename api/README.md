# PROYECTO LUMEN 10 BASE

[![Framework](https://img.shields.io/badge/framework-lumen-blue)](https://lumen.laravel.com/docs/10.x)
![Version](https://img.shields.io/badge/packagist-v10.x-blue)

Proyecto creado como proyecto base, para poder avanzar con los nuevos desarrollos de lumen 10, toda la configuración es basica, esto significa que es posible que se deban hacer otros ajustes.

> **Nota:** Este proyecto esta enfocado en ser la base para los futuros desarrollos

## Librerias usadas

**Las librerias mencionadas, ya se encuentran instaladas y probadas.**

### Oracle
Para poder conectarnos a la base de datos oracle, tenemos una libreria llamada **yajra**, la cual nos permite poder tener el Eloquent de Oracle, esta libreria se puede descargar de la siguiente página **https://yajrabox.com/docs/laravel-oci8/9.0**

### Formularios
Para poder hacer validaciones de los formularios, usamos la libreria llamada **Form-Request**, esta permite poder hacer la validación de los datos de entrada, por ejemplo validar que datos que sean requeridos, vengan en como dato de entrada, esta libreria se puede descargar de la siguiente página **https://www.larablocks.com/package/anik/form-request**

### Sentry 
Sentry es una herramienta que nos permite poder hacer el monitoreo de errores de los proyectos.
Para poder instalar sentry en el proyecto, se deben hacer otros pasos, para que este pueda funcionar en lumen.

- Instalar la libreria: **composer require sentry/sentry-laravel**
- Agregar el registro en archivo app: **$app->register(Sentry\Laravel\ServiceProvider::class);**
- Agregar la configuración en archivo app: **$app->configure('sentry');**
- Crear carpeta config y archivo sentry.php
> return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'traces_sample_rate' => 1.0
];
- Agregar al .env: SENTRY_LARAVEL_DSN=RUTA_CENTRY
