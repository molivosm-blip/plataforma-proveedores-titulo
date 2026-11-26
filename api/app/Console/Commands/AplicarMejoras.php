<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Prepara la base para las mejoras de noviembre 2026. Se puede ejecutar
| más de una vez (no duplica ni borra nada):
|
|   1. proveedor_documentos.fecha_vencimiento (vencimiento de documentos).
|   2. Tabla recuperacion_password (recuperación de clave).
|   3. Si users.role es ENUM de MySQL, agrega el rol 'auditor'.
|
| Uso: php artisan mejoras:aplicar
*/
class AplicarMejoras extends Command
{
    protected $signature = 'mejoras:aplicar';

    protected $description = 'Crea las columnas y tablas de vencimientos, recuperación de clave y rol auditor';

    public function handle(): int
    {
        if (Schema::hasTable('proveedor_documentos') && !Schema::hasColumn('proveedor_documentos', 'fecha_vencimiento')) {
            Schema::table('proveedor_documentos', function (Blueprint $table) {
                $table->date('fecha_vencimiento')->nullable();
            });
            $this->info('Columna proveedor_documentos.fecha_vencimiento creada.');
        }

        if (!Schema::hasTable('recuperacion_password')) {
            Schema::create('recuperacion_password', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->dateTime('expira_at');
                $table->dateTime('usado_at')->nullable();
                $table->dateTime('created_at');
            });
            $this->info('Tabla recuperacion_password creada.');
        }

        $this->permitirRolAuditor();

        $this->info('Listo. Reinicie el servidor de la API.');

        return 0;
    }

    private function permitirRolAuditor(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $columna = DB::selectOne(
            "SELECT COLUMN_TYPE AS tipo, IS_NULLABLE AS nulo FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
        );

        if (!$columna || stripos($columna->tipo, 'enum(') !== 0) {
            $this->info('users.role no es ENUM: el rol auditor ya se puede usar.');

            return;
        }

        if (stripos($columna->tipo, "'auditor'") !== false) {
            return;
        }

        $nuevo = preg_replace('/\)$/', ",'auditor')", $columna->tipo);
        DB::statement('ALTER TABLE users MODIFY role ' . $nuevo . ($columna->nulo === 'NO' ? ' NOT NULL' : ' NULL'));
        $this->info("Rol 'auditor' agregado a users.role.");
    }
}
