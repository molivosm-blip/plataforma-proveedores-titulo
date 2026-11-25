<?php

namespace App\Console\Commands;

use App\Services\DocumentoStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Aplica las correcciones de seguridad del portal. Se puede ejecutar más de
| una vez sin problema:
|
|   1. Agrega a users las columnas del límite de intentos de login
|      (intentos_fallidos y bloqueado_hasta).
|   2. Mueve los documentos que estaban en public/uploads (accesibles por URL
|      directa) a storage/app/uploads, que solo se lee con sesión y permiso.
|
| Uso: php artisan seguridad:aplicar
*/
class AplicarSeguridad extends Command
{
    protected $signature = 'seguridad:aplicar';

    protected $description = 'Crea las columnas de bloqueo de login y mueve los documentos fuera de public/';

    public function handle(DocumentoStorageService $storage): int
    {
        if (!Schema::hasColumn('users', 'intentos_fallidos')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('intentos_fallidos')->default(0);
            });
            $this->info('Columna users.intentos_fallidos creada.');
        }

        if (!Schema::hasColumn('users', 'bloqueado_hasta')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dateTime('bloqueado_hasta')->nullable();
            });
            $this->info('Columna users.bloqueado_hasta creada.');
        }

        [$movidos, $omitidos] = $storage->migrarLegacy();

        $this->info('Documentos movidos a almacenamiento privado: ' . $movidos);

        if ($omitidos > 0) {
            $this->warn('Archivos omitidos (nombre no válido o no se pudieron mover): ' . $omitidos);
        }

        $this->info('Listo. Reinicie el servidor de la API.');

        return 0;
    }
}
