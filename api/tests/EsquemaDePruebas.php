<?php

namespace Tests;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Base SQLite en memoria con las tablas que usan las pruebas de las mejoras
| de noviembre. No toca la base MySQL real.
*/
trait EsquemaDePruebas
{
    protected function prepararBase(): void
    {
        putenv('JWT_SECRET=' . str_repeat('s', 40));
        $_ENV['JWT_SECRET'] = str_repeat('s', 40);
        putenv('MAIL_MODO=log');
        $_ENV['MAIL_MODO'] = 'log';

        config([
            'database.connections.pruebas' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'pruebas',
        ]);
        DB::purge('pruebas');

        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('email');
            $t->string('password_hash')->nullable();
            $t->string('display_name')->nullable();
            $t->string('role');
            $t->string('auth_type')->nullable();
            $t->unsignedBigInteger('id_empresa')->nullable();
            $t->string('area')->nullable();
            $t->integer('active')->default(1);
            $t->string('created_at')->nullable();
            $t->string('updated_at')->nullable();
            $t->unsignedInteger('intentos_fallidos')->default(0);
            $t->dateTime('bloqueado_hasta')->nullable();
        });
        Schema::create('proveedores', function (Blueprint $t) {
            $t->increments('id');
            $t->string('razon_social')->nullable();
            $t->string('rut')->nullable();
            $t->string('giro')->nullable();
            $t->string('tipo')->nullable();
            $t->string('empresa_id')->nullable();
            $t->string('estado')->default('En revisión');
            $t->unsignedBigInteger('proveedor_user_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->string('created_at')->nullable();
            $t->string('updated_at')->nullable();
        });
        Schema::create('proveedor_documentos', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
            $t->string('nombre')->nullable();
            $t->string('area_responsable')->nullable();
            $t->string('responsable_carga')->nullable();
            $t->integer('visible_para_proveedor')->default(1);
            $t->integer('visible_para_internos')->default(1);
            $t->string('estado')->default('Pendiente');
            $t->string('comentario')->nullable();
            $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $t->string('reviewed_at')->nullable();
            $t->string('archivo_nombre')->nullable();
            $t->string('archivo_url')->nullable();
            $t->string('archivo_mime_type')->nullable();
            $t->date('fecha_vencimiento')->nullable();
            $t->string('created_at')->nullable();
            $t->string('updated_at')->nullable();
        });
        Schema::create('proveedor_historial', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
            $t->string('accion')->nullable();
            $t->string('realizado_por')->nullable();
            $t->string('created_at')->nullable();
        });
        Schema::create('proveedor_workflow_steps', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
            $t->string('step_key')->nullable();
            $t->string('step_name')->nullable();
            $t->string('area');
            $t->string('status')->default('Pendiente');
            $t->integer('sort_order')->default(0);
            $t->unsignedBigInteger('assigned_user_id')->nullable();
            $t->unsignedBigInteger('validated_by_user_id')->nullable();
            $t->string('validated_at')->nullable();
            $t->string('comments')->nullable();
            $t->string('created_at')->nullable();
            $t->string('updated_at')->nullable();
        });
        Schema::create('proveedor_validaciones', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
            $t->string('area');
            $t->string('area_name')->nullable();
            $t->string('status')->default('Pendiente');
            $t->string('comments')->nullable();
            $t->unsignedBigInteger('validated_by_user_id')->nullable();
            $t->string('validated_at')->nullable();
            $t->string('created_at')->nullable();
            $t->string('updated_at')->nullable();
        });
        Schema::create('proveedor_detalles', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
        });
        Schema::create('proveedor_sociedades_habilitadas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
        });
        Schema::create('notificaciones', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('proveedor_id')->nullable();
            $t->string('tipo');
            $t->string('referencia')->nullable();
            $t->string('titulo');
            $t->text('mensaje');
            $t->integer('email_enviado')->default(0);
            $t->string('leida_at')->nullable();
            $t->string('created_at')->nullable();
        });
        Schema::create('empresas', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('nombre')->nullable();
        });
        DB::table('empresas')->insert(['id' => 'DIMERC', 'nombre' => 'Dimerc']);
        Schema::create('recuperacion_password', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('token_hash', 64)->unique();
            $t->dateTime('expira_at');
            $t->dateTime('usado_at')->nullable();
            $t->dateTime('created_at');
        });
    }

    protected function crearUsuario(string $email, string $role, ?string $area = null, array $extra = []): User
    {
        return User::create(array_merge([
            'email' => $email, 'role' => $role, 'area' => $area, 'active' => 1,
        ], $extra));
    }

    protected function tokenDe(User $u, ?int $exp = null): string
    {
        return JWT::encode(['sub' => $u->id, 'iat' => time(), 'exp' => $exp ?? time() + 600], env('JWT_SECRET'), 'HS256');
    }

    protected function api(string $metodo, string $uri, ?User $u = null, array $datos = [], ?string $token = null)
    {
        $headers = [];
        $token = $token ?? ($u ? $this->tokenDe($u) : null);

        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $this->call($metodo, '/v1/' . $uri, $datos, [], [], $headers);

        return $this->response;
    }

    protected function crearProveedor(array $extra = []): int
    {
        return DB::table('proveedores')->insertGetId(array_merge([
            'razon_social' => 'Prueba SpA', 'rut' => '76.123.456-0', 'tipo' => 'Nacional',
            'empresa_id' => 'DIMERC', 'estado' => 'En revisión',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ], $extra));
    }

    protected function crearDocumento(int $proveedorId, array $extra = []): int
    {
        return DB::table('proveedor_documentos')->insertGetId(array_merge([
            'proveedor_id' => $proveedorId, 'nombre' => 'Certificado', 'area_responsable' => 'contraloria',
            'responsable_carga' => 'proveedor', 'estado' => 'Pendiente',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ], $extra));
    }
}
