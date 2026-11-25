<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Prepara la base para la página "Mi cuenta". Se puede ejecutar más de una vez:
|
|   1. users.ultimo_ingreso_at y users.ingreso_anterior_at (último ingreso).
|   2. Tabla usuario_preferencias_notificacion (avisos por correo).
|
| Uso: php artisan cuenta:aplicar
*/
class AplicarCuenta extends Command
{
    protected $signature = 'cuenta:aplicar';

    protected $description = 'Crea las columnas y la tabla que usa la página Mi cuenta';

    public function handle(): int
    {
        foreach (['ultimo_ingreso_at', 'ingreso_anterior_at'] as $columna) {
            if (!Schema::hasColumn('users', $columna)) {
                Schema::table('users', function (Blueprint $table) use ($columna) {
                    $table->dateTime($columna)->nullable();
                });
                $this->info('Columna users.' . $columna . ' creada.');
            }
        }

        if (!Schema::hasTable('usuario_preferencias_notificacion')) {
            Schema::create('usuario_preferencias_notificacion', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->string('grupo', 30);
                $table->boolean('correo')->default(true);
                $table->dateTime('updated_at')->nullable();
                $table->primary(['user_id', 'grupo']);
            });
            $this->info('Tabla usuario_preferencias_notificacion creada.');
        }

        $this->info('Listo. Reinicie el servidor de la API.');

        return 0;
    }
}
