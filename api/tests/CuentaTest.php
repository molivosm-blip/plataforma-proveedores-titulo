<?php

namespace Tests;

use App\Models\Notificacion;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CuentaService;
use App\Services\NotificacionService;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Página "Mi cuenta": perfil, cambio de contraseña del proveedor, último
| ingreso y preferencias de aviso por correo.
*/
class CuentaTest extends TestCase
{
    private const CLAVE = 'Clave-Segura-2026';

    protected function setUp(): void
    {
        parent::setUp();

        putenv('JWT_SECRET=' . str_repeat('s', 40));
        $_ENV['JWT_SECRET'] = str_repeat('s', 40);
        putenv('LOGIN_MAX_INTENTOS=5');
        putenv('LOGIN_BLOQUEO_MINUTOS=15');
        putenv('MAIL_MODO=log');

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
            $t->dateTime('ultimo_ingreso_at')->nullable();
            $t->dateTime('ingreso_anterior_at')->nullable();
        });
        Schema::create('proveedores', function (Blueprint $t) {
            $t->increments('id');
            $t->string('razon_social')->nullable();
            $t->string('rut')->nullable();
            $t->string('giro')->nullable();
            $t->string('tipo')->nullable();
            $t->string('estado')->nullable();
            $t->unsignedBigInteger('proveedor_user_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
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
        Schema::create('usuario_preferencias_notificacion', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id');
            $t->string('grupo');
            $t->integer('correo')->default(1);
            $t->string('updated_at')->nullable();
            $t->primary(['user_id', 'grupo']);
        });

        $ref = new \ReflectionProperty(AuthService::class, 'columnasBloqueo');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    private function usuario(string $email, string $role, ?string $clave = null, ?string $area = null): User
    {
        return User::create([
            'email' => $email, 'role' => $role, 'area' => $area, 'active' => 1,
            'display_name' => 'Nombre ' . $role,
            'password_hash' => $clave ? app(AuthService::class)->hashPassword($clave) : null,
        ]);
    }

    private function proveedor(): User
    {
        $u = $this->usuario('contacto@proveedor.cl', 'proveedor', self::CLAVE);
        DB::table('proveedores')->insert([
            'razon_social' => 'Embalajes Sur SpA', 'rut' => '76.111.222-3', 'tipo' => 'Nacional',
            'giro' => 'Envases', 'estado' => 'En revisión', 'proveedor_user_id' => $u->id,
        ]);

        return $u;
    }

    private function llamar(string $metodo, string $uri, ?User $u = null, array $datos = [])
    {
        $server = [];

        if ($u) {
            $token = JWT::encode(['sub' => $u->id, 'iat' => time(), 'exp' => time() + 600], env('JWT_SECRET'), 'HS256');
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $this->call($metodo, '/v1/' . $uri, $datos, [], [], $server);

        return $this->response;
    }

    private function cuerpo($resp): array
    {
        return json_decode($resp->getContent(), true) ?: [];
    }

    // ---------------------------------------------------------------- perfil

    public function test_perfil_del_proveedor_incluye_sus_datos_y_permite_cambiar_clave(): void
    {
        $u = $this->proveedor();
        $r = $this->llamar('GET', 'cuenta', $u);

        $this->assertSame(200, $r->getStatusCode());
        $d = $this->cuerpo($r)['data'];
        $this->assertSame('contacto@proveedor.cl', $d['email']);
        $this->assertTrue($d['puedeCambiarPassword']);
        $this->assertSame('Embalajes Sur SpA', $d['proveedor']['razonSocial']);
        $this->assertSame('76.111.222-3', $d['proveedor']['rut']);
        $this->assertArrayNotHasKey('password_hash', $d);
    }

    public function test_perfil_interno_no_permite_cambiar_clave_ni_trae_proveedor(): void
    {
        $u = $this->usuario('ana@dimerc.cl', 'usuario_area', null, 'contraloria');
        $d = $this->cuerpo($this->llamar('GET', 'cuenta', $u))['data'];

        $this->assertFalse($d['puedeCambiarPassword']);
        $this->assertNull($d['proveedor']);
        $this->assertSame('contraloria', $d['area']);
        $this->assertSame('entra', $d['authType']);
    }

    public function test_cuenta_exige_sesion(): void
    {
        $this->assertSame(401, $this->llamar('GET', 'cuenta')->getStatusCode());
        $this->assertSame(401, $this->llamar('PUT', 'cuenta/password', null, ['passwordActual' => 'a', 'passwordNueva' => 'b'])->getStatusCode());
        $this->assertSame(401, $this->llamar('GET', 'cuenta/notificaciones')->getStatusCode());
    }

    // ----------------------------------------------------------- contraseña

    public function test_cambiar_password_guarda_el_hash_nuevo_y_avisa(): void
    {
        $u = $this->proveedor();
        $r = $this->llamar('PUT', 'cuenta/password', $u, ['passwordActual' => self::CLAVE, 'passwordNueva' => 'Otra-Clave-2027']);

        $this->assertSame(200, $r->getStatusCode(), $r->getContent());

        $auth = app(AuthService::class);
        $hash = DB::table('users')->where('id', $u->id)->value('password_hash');
        $this->assertTrue($auth->passwordMatches('Otra-Clave-2027', $hash));
        $this->assertFalse($auth->passwordMatches(self::CLAVE, $hash));
        $this->assertSame(1, Notificacion::where('user_id', $u->id)->where('tipo', 'seguridad_contrasena')->count());
        $this->assertStringNotContainsString('Otra-Clave', Notificacion::first()->mensaje);
    }

    public function test_cambiar_password_con_la_actual_incorrecta_se_rechaza(): void
    {
        $u = $this->proveedor();
        $r = $this->llamar('PUT', 'cuenta/password', $u, ['passwordActual' => 'Equivocada-123', 'passwordNueva' => 'Otra-Clave-2027']);

        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame('La contraseña actual no es correcta.', $this->cuerpo($r)['message']);
        $hash = DB::table('users')->where('id', $u->id)->value('password_hash');
        $this->assertTrue(app(AuthService::class)->passwordMatches(self::CLAVE, $hash));
    }

    public function test_adivinar_la_clave_actual_desde_una_sesion_bloquea_la_cuenta(): void
    {
        $u = $this->proveedor();

        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(422, $this->llamar('PUT', 'cuenta/password', $u, ['passwordActual' => 'Mala-' . $i . 'xyz', 'passwordNueva' => 'Otra-Clave-2027'])->getStatusCode());
        }

        $this->assertSame(429, $this->llamar('PUT', 'cuenta/password', $u, ['passwordActual' => 'Mala-5xyz', 'passwordNueva' => 'Otra-Clave-2027'])->getStatusCode());
        // Con la cuenta bloqueada ni siquiera la clave correcta sirve.
        $this->assertSame(429, $this->llamar('PUT', 'cuenta/password', $u, ['passwordActual' => self::CLAVE, 'passwordNueva' => 'Otra-Clave-2027'])->getStatusCode());
    }

    public function test_la_clave_nueva_debe_cumplir_la_politica(): void
    {
        $u = $this->proveedor();

        $casos = [
            'corta'          => 'Ab1',
            'sin numero'     => 'SoloLetrasLargas',
            'con el correo'  => 'Xcontacto-2027',
            'habitual'       => 'Password-12345',
            'igual a actual' => self::CLAVE,
        ];

        foreach ($casos as $nombre => $nueva) {
            $r = $this->llamar('PUT', 'cuenta/password', $u, ['passwordActual' => self::CLAVE, 'passwordNueva' => $nueva]);
            $this->assertSame(422, $r->getStatusCode(), $nombre . ': ' . $r->getContent());
        }

        $hash = DB::table('users')->where('id', $u->id)->value('password_hash');
        $this->assertTrue(app(AuthService::class)->passwordMatches(self::CLAVE, $hash));
    }

    public function test_un_usuario_interno_no_puede_cambiar_clave(): void
    {
        $u = $this->usuario('ana@dimerc.cl', 'admin_portal');
        $r = $this->llamar('PUT', 'cuenta/password', $u, ['passwordActual' => 'x', 'passwordNueva' => 'Otra-Clave-2027']);

        $this->assertSame(403, $r->getStatusCode());
    }

    // ---------------------------------------------------------- último ingreso

    public function test_el_ingreso_actual_y_el_anterior_se_guardan_en_cada_inicio_de_sesion(): void
    {
        $u = $this->proveedor();
        $auth = app(AuthService::class);

        $auth->login(['email' => $u->email, 'password' => self::CLAVE]);
        $d1 = $this->cuerpo($this->llamar('GET', 'cuenta', $u))['data'];
        $this->assertNotNull($d1['ingresoActual']);
        $this->assertNull($d1['ingresoAnterior']);

        DB::table('users')->where('id', $u->id)->update(['ultimo_ingreso_at' => '2026-11-01 10:00:00']);

        $auth->login(['email' => $u->email, 'password' => self::CLAVE]);
        $d2 = $this->cuerpo($this->llamar('GET', 'cuenta', $u))['data'];
        $this->assertSame('2026-11-01 10:00:00', $d2['ingresoAnterior']);
        $this->assertNotSame('2026-11-01 10:00:00', $d2['ingresoActual']);
    }

    // --------------------------------------------------------- preferencias

    public function test_cada_rol_ve_solo_los_grupos_que_le_corresponden(): void
    {
        $prov = $this->proveedor();
        $int = $this->usuario('ana@dimerc.cl', 'usuario_area', null, 'contraloria');

        $g1 = array_column($this->cuerpo($this->llamar('GET', 'cuenta/notificaciones', $prov))['data']['items'], 'grupo');
        $g2 = array_column($this->cuerpo($this->llamar('GET', 'cuenta/notificaciones', $int))['data']['items'], 'grupo');

        $this->assertSame(['estado', 'documentos'], $g1);
        $this->assertSame(['estado', 'documentos', 'plazos', 'alertas'], $g2);

        foreach ($this->cuerpo($this->llamar('GET', 'cuenta/notificaciones', $prov))['data']['items'] as $item) {
            $this->assertTrue($item['correo']);
        }
    }

    public function test_guardar_preferencias_y_leerlas_de_nuevo(): void
    {
        $u = $this->usuario('ana@dimerc.cl', 'usuario_area', null, 'contraloria');

        $r = $this->llamar('PUT', 'cuenta/notificaciones', $u, ['preferencias' => ['documentos' => false, 'alertas' => false, 'inventado' => false]]);
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());

        $items = [];
        foreach ($this->cuerpo($this->llamar('GET', 'cuenta/notificaciones', $u))['data']['items'] as $i) {
            $items[$i['grupo']] = $i['correo'];
        }

        $this->assertSame(['estado' => true, 'documentos' => false, 'plazos' => true, 'alertas' => false], $items);
        $this->assertSame(0, DB::table('usuario_preferencias_notificacion')->where('grupo', 'inventado')->count());

        // Volver a activar actualiza la misma fila.
        $this->llamar('PUT', 'cuenta/notificaciones', $u, ['preferencias' => ['documentos' => true]]);
        $this->assertSame(1, DB::table('usuario_preferencias_notificacion')->where('user_id', $u->id)->where('grupo', 'documentos')->count());
        $this->assertSame(1, (int) DB::table('usuario_preferencias_notificacion')->where('user_id', $u->id)->where('grupo', 'documentos')->value('correo'));
    }

    public function test_preferencias_invalidas_se_rechazan(): void
    {
        $u = $this->proveedor();

        $this->assertSame(422, $this->llamar('PUT', 'cuenta/notificaciones', $u, ['preferencias' => ['estado' => 'no']])->getStatusCode());
        $this->assertSame(422, $this->llamar('PUT', 'cuenta/notificaciones', $u, [])->getStatusCode());
    }

    public function test_las_preferencias_son_solo_del_usuario_autenticado(): void
    {
        $a = $this->proveedor();
        $b = $this->usuario('otro@proveedor.cl', 'proveedor', self::CLAVE);

        $this->llamar('PUT', 'cuenta/notificaciones', $a, ['preferencias' => ['estado' => false]]);

        $deB = [];
        foreach ($this->cuerpo($this->llamar('GET', 'cuenta/notificaciones', $b))['data']['items'] as $i) {
            $deB[$i['grupo']] = $i['correo'];
        }
        $this->assertTrue($deB['estado']);
    }

    public function test_el_aviso_siempre_queda_en_la_campana_y_el_correo_respeta_la_preferencia(): void
    {
        $u = $this->proveedor();
        $svc = app(NotificacionService::class);

        // Sin preferencias: se envía el correo (comportamiento anterior).
        $n1 = $svc->notificar($u, null, 'documento_observado', 'Doc', 'Mensaje');
        $this->assertTrue((bool) $n1->email_enviado);

        $this->llamar('PUT', 'cuenta/notificaciones', $u, ['preferencias' => ['documentos' => false]]);

        // Grupo apagado: queda en la campana, sin correo.
        $n2 = $svc->notificar($u, null, 'documento_observado', 'Doc 2', 'Mensaje');
        $this->assertSame(2, Notificacion::where('user_id', $u->id)->count());
        $this->assertFalse((bool) Notificacion::find($n2->id)->email_enviado);

        // Otro grupo sigue enviándose.
        $n3 = $svc->notificar($u, null, 'validacion_observado', 'Estado', 'Mensaje');
        $this->assertTrue((bool) Notificacion::find($n3->id)->email_enviado);

        // Los avisos de seguridad y los tipos sin grupo se envían siempre.
        $n4 = $svc->notificar($u, null, 'seguridad_contrasena', 'Clave', 'Mensaje');
        $this->assertTrue((bool) Notificacion::find($n4->id)->email_enviado);
    }

    public function test_cada_tipo_de_aviso_del_sistema_cae_en_el_grupo_esperado(): void
    {
        $c = app(CuentaService::class);

        $esperado = [
            'proveedor_aprobado' => 'estado', 'validacion_observado' => 'estado', 'validacion_rechazado' => 'estado',
            'etapa_disponible' => 'estado', 'sap_creado' => 'estado', 'datos_maestros' => 'estado',
            'documento_subido' => 'documentos', 'documento_observado' => 'documentos', 'declaracion_firmada' => 'documentos',
            'plazo_vencido' => 'plazos', 'reevaluacion_alerta' => 'plazos', 'alerta_pep' => 'alertas',
            'seguridad_contrasena' => null, 'cualquier_otro' => null,
        ];

        foreach ($esperado as $tipo => $grupo) {
            $this->assertSame($grupo, $c->grupoDeTipo($tipo), $tipo);
        }
    }

    public function test_sin_la_tabla_de_preferencias_el_portal_sigue_enviando_todo(): void
    {
        Schema::drop('usuario_preferencias_notificacion');
        $u = $this->proveedor();

        $this->assertTrue(app(CuentaService::class)->correoActivado($u->id, 'documento_observado'));
        $r = $this->llamar('GET', 'cuenta/notificaciones', $u);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertFalse($this->cuerpo($r)['data']['disponible']);
        $this->assertSame(422, $this->llamar('PUT', 'cuenta/notificaciones', $u, ['preferencias' => ['estado' => false]])->getStatusCode());
    }

    public function test_comando_cuenta_aplicar_crea_lo_necesario_y_es_repetible(): void
    {
        Schema::drop('usuario_preferencias_notificacion');
        Schema::drop('users');
        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('email');
        });

        $this->assertSame(0, Artisan::call('cuenta:aplicar'));
        $this->assertTrue(Schema::hasColumn('users', 'ultimo_ingreso_at'));
        $this->assertTrue(Schema::hasColumn('users', 'ingreso_anterior_at'));
        $this->assertTrue(Schema::hasTable('usuario_preferencias_notificacion'));

        $this->assertSame(0, Artisan::call('cuenta:aplicar'));
    }
}
