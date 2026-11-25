<?php

namespace Tests;

use App\Models\User;
use App\Services\ProveedorEstadoService;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| El estado general del proveedor lo decide una sola regla
| (ProveedorEstadoService). Estas pruebas verifican la regla y que los tres
| puntos que lo modifican (documentos, validaciones por área y etapas del
| workflow) ya no se contradigan.
*/
class EstadoUnicoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('JWT_SECRET=' . str_repeat('s', 40));
        $_ENV['JWT_SECRET'] = str_repeat('s', 40);

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
        });
        Schema::create('proveedores', function (Blueprint $t) {
            $t->increments('id');
            $t->string('razon_social')->nullable();
            $t->string('estado')->default('En revisión');
            $t->unsignedBigInteger('proveedor_user_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
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
        Schema::create('proveedor_documentos', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
            $t->string('nombre')->nullable();
            $t->integer('visible_para_internos')->default(1);
            $t->string('estado')->default('Pendiente');
        });
        Schema::create('proveedor_detalles', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
        });
        Schema::create('proveedor_sociedades_habilitadas', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
        });
        Schema::create('proveedor_historial', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
            $t->string('accion')->nullable();
            $t->string('realizado_por')->nullable();
            $t->string('created_at')->nullable();
        });
    }

    private const AREAS = ['pm', 'contraloria', 'legal_crediticia', 'legal_judicial', 'datos_maestros'];

    private function proveedor(string $estadoAreas = 'Pendiente', array $docs = []): int
    {
        $id = DB::table('proveedores')->insertGetId(['razon_social' => 'Prueba SpA']);

        foreach (self::AREAS as $i => $area) {
            DB::table('proveedor_validaciones')->insert([
                'proveedor_id' => $id, 'area' => $area, 'area_name' => $area, 'status' => $estadoAreas,
            ]);
            DB::table('proveedor_workflow_steps')->insert([
                'proveedor_id' => $id, 'area' => $area, 'step_key' => $area, 'step_name' => $area,
                'status' => $estadoAreas, 'sort_order' => $i + 1,
            ]);
        }

        foreach ($docs as $estado) {
            DB::table('proveedor_documentos')->insert(['proveedor_id' => $id, 'estado' => $estado]);
        }

        return $id;
    }

    private function admin(): User
    {
        return User::create(['email' => 'admin@dimerc.cl', 'role' => 'admin_portal', 'active' => 1]);
    }

    private function llamar(string $metodo, string $uri, User $u, array $datos = [])
    {
        $token = JWT::encode(['sub' => $u->id, 'iat' => time(), 'exp' => time() + 600], env('JWT_SECRET'), 'HS256');
        $this->call($metodo, '/v1/' . $uri, $datos, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return $this->response;
    }

    private function estado(int $id): string
    {
        return DB::table('proveedores')->where('id', $id)->value('estado');
    }

    /* ----------------------------- La regla ----------------------------- */

    public function test_regla_de_prioridad(): void
    {
        $s = app(ProveedorEstadoService::class);
        $aprob = array_fill(0, 5, 'Aprobado');

        // Rechazado manda sobre todo.
        $this->assertSame('Rechazado', $s->resolverEstado(array_merge(['Rechazado'], $aprob), $aprob, ['Observado']));
        // Observado por validación, por etapa o por documento.
        $this->assertSame('Observado', $s->resolverEstado(['Observado', 'Aprobado'], [], []));
        $this->assertSame('Observado', $s->resolverEstado([], ['Observado'], []));
        $this->assertSame('Observado', $s->resolverEstado($aprob, $aprob, ['Aprobado', 'Observado']));
        // Todas las áreas aprobadas: aprobado, aunque queden documentos por revisar.
        $this->assertSame('Aprobado', $s->resolverEstado($aprob, $aprob, ['Subido', 'Pendiente']));
        // Una sola área pendiente: en revisión.
        $this->assertSame('En revisión', $s->resolverEstado(['Aprobado', 'Pendiente'], ['Aprobado', 'Pendiente'], ['Aprobado']));
        // Si las dos tablas de áreas se contradicen, gana la más restrictiva.
        $this->assertSame('En revisión', $s->resolverEstado($aprob, ['Aprobado', 'En revisión'], []));
    }

    public function test_el_caso_que_antes_se_contradecia_documentos_aprobados_con_areas_pendientes(): void
    {
        $id = $this->proveedor('Pendiente', ['Aprobado', 'Aprobado']);

        // Antes: la regla por documentos lo dejaba "Aprobado" aunque ninguna área hubiera revisado.
        $this->assertSame('En revisión', app(ProveedorEstadoService::class)->recalcularEstadoProveedor($id));
    }

    public function test_proveedor_antiguo_sin_validaciones_se_calcula_por_documentos(): void
    {
        $s = app(ProveedorEstadoService::class);

        $this->assertSame('Aprobado', $s->resolverEstado([], [], ['Aprobado', 'Aprobado']));
        $this->assertSame('En revisión', $s->resolverEstado([], [], ['Aprobado', 'Subido']));
        $this->assertSame('En revisión', $s->resolverEstado([], [], []));
    }

    /* ----------------------------- Los tres caminos coinciden ----------------------------- */

    public function test_un_documento_observado_y_luego_aprobado_cambia_el_estado_igual_en_todos_los_caminos(): void
    {
        $id = $this->proveedor('Aprobado', ['Aprobado']);
        $svc = app(ProveedorEstadoService::class);

        $this->assertSame('Aprobado', $svc->recalcularEstadoProveedor($id));

        DB::table('proveedor_documentos')->where('proveedor_id', $id)->update(['estado' => 'Observado']);
        $this->assertSame('Observado', $svc->recalcularEstadoProveedor($id));

        DB::table('proveedor_documentos')->where('proveedor_id', $id)->update(['estado' => 'Aprobado']);
        $this->assertSame('Aprobado', $svc->recalcularEstadoProveedor($id));
    }

    public function test_cambiar_una_validacion_actualiza_la_etapa_y_el_estado(): void
    {
        $id = $this->proveedor('En revisión');
        $admin = $this->admin();

        $res = $this->llamar('PUT', "validaciones/proveedores/$id/areas/contraloria", $admin, ['status' => 'Observado', 'comments' => 'Falta poder']);

        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame('Observado', DB::table('proveedor_workflow_steps')->where('proveedor_id', $id)->where('area', 'contraloria')->value('status'));
        $this->assertSame('Observado', $this->estado($id));

        foreach (self::AREAS as $area) {
            $r = $this->llamar('PUT', "validaciones/proveedores/$id/areas/$area", $admin, ['status' => 'Aprobado']);
            $this->assertSame(200, $r->getStatusCode(), $area . ' ' . $r->getContent());
        }

        $this->assertSame('Aprobado', $this->estado($id));
    }

    public function test_cambiar_una_etapa_del_workflow_actualiza_la_validacion_y_el_estado(): void
    {
        $id = $this->proveedor('En revisión');
        $admin = $this->admin();
        $stepId = DB::table('proveedor_workflow_steps')->where('proveedor_id', $id)->where('area', 'legal_judicial')->value('id');

        $res = $this->llamar('PUT', "workflow/proveedores/$id/steps/$stepId", $admin, ['status' => 'Rechazado', 'comments' => 'Antecedentes']);

        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        // Antes la validación quedaba "En revisión" mientras la etapa decía "Rechazado".
        $this->assertSame('Rechazado', DB::table('proveedor_validaciones')->where('proveedor_id', $id)->where('area', 'legal_judicial')->value('status'));
        $this->assertSame('Rechazado', $this->estado($id));

        $res = $this->llamar('PUT', "workflow/proveedores/$id/steps/$stepId/reabrir", $admin);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame('En revisión', DB::table('proveedor_validaciones')->where('proveedor_id', $id)->where('area', 'legal_judicial')->value('status'));
        $this->assertSame('En revisión', $this->estado($id));
    }

    public function test_el_comando_de_reconciliacion_informa_y_corrige(): void
    {
        $id = $this->proveedor('Pendiente', ['Aprobado']);
        DB::table('proveedores')->where('id', $id)->update(['estado' => 'Aprobado']); // estado contradictorio

        \Illuminate\Support\Facades\Artisan::call('estados:reconciliar');
        $this->assertSame('Aprobado', $this->estado($id), 'sin --aplicar no debe modificar');
        $this->assertStringContainsString('calculado "En revisión"', \Illuminate\Support\Facades\Artisan::output());

        \Illuminate\Support\Facades\Artisan::call('estados:reconciliar', ['--aplicar' => true]);
        $this->assertSame('En revisión', $this->estado($id));
        $this->assertSame(1, DB::table('proveedor_historial')->where('proveedor_id', $id)->count());
    }
    public function test_el_estado_no_se_puede_fijar_a_mano_si_contradice_la_regla(): void
    {
        $id = $this->proveedor('Pendiente', ['Aprobado']);
        $admin = $this->admin();

        $res = $this->llamar('PUT', "interno/proveedores/$id/estado", $admin, ['estado' => 'Aprobado']);

        $this->assertSame(422, $res->getStatusCode(), $res->getContent());
        $cuerpo = json_decode($res->getContent(), true);
        $this->assertSame('En revisión', $cuerpo['estadoCalculado']);
        $this->assertSame('En revisión', $this->estado($id));
    }
}
