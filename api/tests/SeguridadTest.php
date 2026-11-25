<?php

namespace Tests;

use App\Exceptions\LoginBloqueadoException;
use App\Models\User;
use App\Services\AuthService;
use App\Services\DocumentoStorageService;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Pruebas de las dos correcciones de seguridad:
|   1. Documentos servidos solo con sesión y permiso (y rutas manipuladas rechazadas).
|   2. Política de contraseñas y bloqueo por intentos fallidos de login.
|
| Usan una base SQLite en memoria con solo las tablas necesarias.
*/
class SeguridadTest extends TestCase
{
    private const CLAVE = 'Clave-Segura-2026';

    private $archivosCreados = [];

    protected function setUp(): void
    {
        parent::setUp();

        putenv('JWT_SECRET=' . str_repeat('s', 40));
        $_ENV['JWT_SECRET'] = str_repeat('s', 40);
        putenv('LOGIN_MAX_INTENTOS=5');
        putenv('LOGIN_BLOQUEO_MINUTOS=15');

        config([
            'database.connections.pruebas' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'pruebas',
        ]);
        DB::purge('pruebas');

        $this->crearTablas();

        $ref = new \ReflectionProperty(AuthService::class, 'columnasBloqueo');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        foreach ($this->archivosCreados as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    private function crearTablas(): void
    {
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
            $t->unsignedBigInteger('proveedor_user_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
        });

        Schema::create('proveedor_documentos', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
            $t->string('nombre');
            $t->string('area_responsable')->nullable();
            $t->string('responsable_carga')->nullable();
            $t->integer('visible_para_proveedor')->default(1);
            $t->integer('visible_para_internos')->default(1);
            $t->string('estado')->default('Subido');
            $t->string('archivo_nombre')->nullable();
            $t->string('archivo_url')->nullable();
            $t->string('archivo_mime_type')->nullable();
        });
    }

    private function usuario(string $email, string $role, ?string $area = null, ?string $clave = null): User
    {
        return User::create([
            'email' => $email,
            'role' => $role,
            'area' => $area,
            'active' => 1,
            'password_hash' => $clave ? app(AuthService::class)->hashPassword($clave) : null,
        ]);
    }

    private function token(User $u): string
    {
        return JWT::encode(['sub' => $u->id, 'iat' => time(), 'exp' => time() + 600], env('JWT_SECRET'), 'HS256');
    }

    private function descargar(int $docId, ?User $u)
    {
        $headers = $u ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($u)] : [];
        $this->call('GET', '/v1/documentos/' . $docId . '/archivo', [], [], [], $headers);

        return $this->response;
    }

    /** Crea proveedor + documento con un PDF real en el almacenamiento privado. */
    private function documento(User $dueno, array $extra = []): int
    {
        $storage = app(DocumentoStorageService::class);
        $nombre = 'test_' . uniqid() . '.pdf';
        $url = $storage->guardarContenido('proveedor_documentos', $nombre, "%PDF-1.4\ncontenido de prueba");
        $this->archivosCreados[] = $storage->resolver($url);

        $pid = DB::table('proveedores')->insertGetId(['proveedor_user_id' => $dueno->id]);

        return DB::table('proveedor_documentos')->insertGetId(array_merge([
            'proveedor_id' => $pid,
            'nombre' => 'Documento de prueba',
            'area_responsable' => 'contraloria',
            'responsable_carga' => 'proveedor',
            'archivo_nombre' => 'prueba.pdf',
            'archivo_url' => $url,
        ], $extra));
    }

    /* ----------------------------- Descarga protegida ----------------------------- */

    public function test_sin_sesion_no_se_puede_descargar(): void
    {
        $dueno = $this->usuario('dueno@empresa.cl', 'proveedor');
        $doc = $this->documento($dueno);

        $this->assertSame(401, $this->descargar($doc, null)->getStatusCode());
    }

    public function test_el_proveedor_dueno_descarga_su_documento(): void
    {
        $dueno = $this->usuario('dueno@empresa.cl', 'proveedor');
        $doc = $this->documento($dueno);

        $res = $this->descargar($doc, $dueno);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('contenido de prueba', file_get_contents($res->getFile()->getPathname()));
    }

    public function test_otro_proveedor_no_puede_descargar_documentos_ajenos(): void
    {
        $dueno = $this->usuario('dueno@empresa.cl', 'proveedor');
        $otro = $this->usuario('otro@empresa.cl', 'proveedor');
        $doc = $this->documento($dueno);

        $this->assertSame(404, $this->descargar($doc, $otro)->getStatusCode());
    }

    public function test_el_proveedor_no_ve_documentos_internos(): void
    {
        $dueno = $this->usuario('dueno@empresa.cl', 'proveedor');
        $doc = $this->documento($dueno, ['visible_para_proveedor' => 0]);

        $this->assertSame(404, $this->descargar($doc, $dueno)->getStatusCode());
    }

    public function test_usuario_de_area_solo_ve_documentos_de_su_area(): void
    {
        $dueno = $this->usuario('dueno@empresa.cl', 'proveedor');
        $doc = $this->documento($dueno, ['area_responsable' => 'contraloria']);

        $contraloria = $this->usuario('contra@dimerc.cl', 'usuario_area', 'contraloria');
        $legal = $this->usuario('legal@dimerc.cl', 'usuario_area', 'legal_crediticia');

        $this->assertSame(200, $this->descargar($doc, $contraloria)->getStatusCode());
        $this->assertSame(404, $this->descargar($doc, $legal)->getStatusCode());
    }

    public function test_administrador_global_puede_descargar(): void
    {
        $dueno = $this->usuario('dueno@empresa.cl', 'proveedor');
        $doc = $this->documento($dueno);
        $admin = $this->usuario('admin@dimerc.cl', 'admin_portal');

        $this->assertSame(200, $this->descargar($doc, $admin)->getStatusCode());
    }

    public function test_documento_inexistente_o_sin_archivo_responde_404(): void
    {
        $dueno = $this->usuario('dueno@empresa.cl', 'proveedor');
        $doc = $this->documento($dueno, ['archivo_url' => null]);

        $this->assertSame(404, $this->descargar($doc, $dueno)->getStatusCode());
        $this->assertSame(404, $this->descargar(9999, $dueno)->getStatusCode());
    }

    public function test_una_ruta_manipulada_en_la_base_no_sale_del_almacenamiento(): void
    {
        $dueno = $this->usuario('dueno@empresa.cl', 'proveedor');

        foreach ([
            '/uploads/proveedor_documentos/../../.env',
            '/uploads/proveedor_documentos/..%2F..%2F.env',
            '/uploads/otra_carpeta/archivo.pdf',
            '../../.env',
            '/etc/passwd',
            'C:\\Windows\\win.ini',
            '/uploads/proveedor_documentos/a/b.pdf',
        ] as $ruta) {
            $doc = $this->documento($dueno, ['archivo_url' => $ruta]);
            $this->assertSame(404, $this->descargar($doc, $dueno)->getStatusCode(), $ruta);
        }
    }

    public function test_storage_valida_rutas(): void
    {
        $s = app(DocumentoStorageService::class);

        $this->assertTrue($s->esRutaValida('/uploads/proveedor_documentos/doc_1_1700000000.pdf'));
        $this->assertTrue($s->esRutaValida('/uploads/proveedor_fichas/ficha_proveedor_3_1700000000.pdf'));
        $this->assertFalse($s->esRutaValida('/uploads/proveedor_documentos/../x.pdf'));
        $this->assertFalse($s->esRutaValida('/uploads/proveedor_documentos/.htaccess'));
        $this->assertFalse($s->esRutaValida('/uploads/proveedor_documentos/a..b.pdf'));
        $this->assertFalse($s->esRutaValida('http://sitio.cl/uploads/proveedor_documentos/a.pdf'));
        $this->assertFalse($s->esRutaValida(null));

        $this->expectException(\InvalidArgumentException::class);
        $s->guardarContenido('proveedor_documentos', '../escape.pdf', 'x');
    }

    public function test_archivos_antiguos_de_public_siguen_disponibles_y_se_migran(): void
    {
        $s = app(DocumentoStorageService::class);
        $dir = $s->raizLegacy() . '/proveedor_documentos';
        @mkdir($dir, 0775, true);
        $nombre = 'legacy_' . uniqid() . '.pdf';
        file_put_contents($dir . '/' . $nombre, '%PDF legacy');
        $url = '/uploads/proveedor_documentos/' . $nombre;

        $this->assertSame(
            str_replace('\\', '/', $dir . '/' . $nombre),
            str_replace('\\', '/', $s->resolver($url))
        );

        [$movidos] = $s->migrarLegacy();
        $this->assertGreaterThanOrEqual(1, $movidos);
        $this->assertFileDoesNotExist($dir . '/' . $nombre);

        $nuevo = $s->resolver($url);
        $this->assertNotNull($nuevo);
        $this->assertStringContainsString('storage', $nuevo);
        $this->archivosCreados[] = $nuevo;
        @rmdir($dir);
        @rmdir($s->raizLegacy());
    }

    /* ----------------------------- Política de contraseñas ----------------------------- */

    public function test_politica_de_contrasenas(): void
    {
        $auth = app(AuthService::class);

        $auth->validarPassword('Clave-Segura-2026', 'ana@empresa.cl');
        $this->assertTrue(true);

        $casos = [
            'Corta1'                => 'al menos 10',
            'soloLetrasSinNumeros'  => 'una letra y un número',
            '12345678901234'        => 'una letra y un número',
            'Xana.perez-2026'       => 'correo',
            'MiPassword2026'        => 'común',
            str_repeat('a1', 40)    => '72',
        ];

        foreach ($casos as $clave => $fragmento) {
            try {
                $auth->validarPassword($clave, 'ana.perez@empresa.cl');
                $this->fail('Debió rechazar: ' . $clave);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($fragmento, $e->getMessage(), $clave);
            }
        }
    }

    public function test_hash_password_aplica_la_politica_salvo_al_migrar_md5(): void
    {
        $auth = app(AuthService::class);

        try {
            $auth->hashPassword('abc12345');
            $this->fail('Debió rechazar una contraseña corta');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        $hash = $auth->hashPassword('abc12345', null, false);
        $this->assertTrue(password_verify('abc12345', $hash));
    }

    /* ----------------------------- Bloqueo por intentos ----------------------------- */

    public function test_cinco_fallos_bloquean_la_cuenta_aunque_luego_se_use_la_clave_correcta(): void
    {
        $this->usuario('prov@empresa.cl', 'proveedor', null, self::CLAVE);
        $auth = app(AuthService::class);

        for ($i = 1; $i <= 4; $i++) {
            try {
                $auth->login(['email' => 'prov@empresa.cl', 'password' => 'incorrecta' . $i]);
                $this->fail('Debió fallar');
            } catch (LoginBloqueadoException $e) {
                $this->fail('No debía bloquear antes del quinto intento');
            } catch (\Exception $e) {
                $this->assertSame('Credenciales inválidas', $e->getMessage());
            }
        }

        try {
            $auth->login(['email' => 'prov@empresa.cl', 'password' => 'incorrecta5']);
            $this->fail('El quinto fallo debía bloquear');
        } catch (LoginBloqueadoException $e) {
            $this->assertStringContainsString('bloqueada', $e->getMessage());
        }

        $this->expectException(LoginBloqueadoException::class);
        $auth->login(['email' => 'prov@empresa.cl', 'password' => self::CLAVE]);
    }

    public function test_login_correcto_reinicia_el_contador(): void
    {
        $u = $this->usuario('prov@empresa.cl', 'proveedor', null, self::CLAVE);
        $auth = app(AuthService::class);

        for ($i = 0; $i < 3; $i++) {
            try {
                $auth->login(['email' => 'prov@empresa.cl', 'password' => 'mala']);
            } catch (\Exception $e) {
            }
        }
        $this->assertSame(3, (int) DB::table('users')->where('id', $u->id)->value('intentos_fallidos'));

        $sesion = $auth->login(['email' => 'prov@empresa.cl', 'password' => self::CLAVE]);

        $this->assertNotEmpty($sesion['token']);
        $this->assertSame(0, (int) DB::table('users')->where('id', $u->id)->value('intentos_fallidos'));
    }

    public function test_el_bloqueo_vence_con_el_tiempo(): void
    {
        $u = $this->usuario('prov@empresa.cl', 'proveedor', null, self::CLAVE);
        DB::table('users')->where('id', $u->id)->update([
            'bloqueado_hasta' => date('Y-m-d H:i:s', time() - 60),
            'intentos_fallidos' => 0,
        ]);

        $sesion = app(AuthService::class)->login(['email' => 'prov@empresa.cl', 'password' => self::CLAVE]);

        $this->assertNotEmpty($sesion['token']);
        $this->assertNull(DB::table('users')->where('id', $u->id)->value('bloqueado_hasta'));
    }

    public function test_endpoint_de_login_responde_429_cuando_esta_bloqueado(): void
    {
        $u = $this->usuario('prov@empresa.cl', 'proveedor', null, self::CLAVE);
        DB::table('users')->where('id', $u->id)->update([
            'bloqueado_hasta' => date('Y-m-d H:i:s', time() + 600),
        ]);

        $this->call('POST', '/v1/auth/login', ['email' => 'prov@empresa.cl', 'password' => self::CLAVE]);

        $this->assertSame(429, $this->response->getStatusCode());
        $this->assertStringContainsString('bloqueada', $this->response->getContent());
    }

    public function test_usuario_inexistente_responde_mensaje_generico(): void
    {
        $this->call('POST', '/v1/auth/login', ['email' => 'nadie@empresa.cl', 'password' => 'cualquiera123']);

        $this->assertSame(401, $this->response->getStatusCode());
        $this->assertSame('Credenciales inválidas', json_decode($this->response->getContent(), true)['message']);
    }
    /* ----------------------------- Validación de la subida ----------------------------- */

    private function validarSubida(string $nombre, string $contenido): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sub');
        file_put_contents($tmp, $contenido);
        $archivo = new \Symfony\Component\HttpFoundation\File\UploadedFile($tmp, $nombre, null, null, true);

        $controller = app(\App\Http\Controllers\ProveedorDocumentosController::class);
        $m = new \ReflectionMethod($controller, 'validateArchivo');
        $m->setAccessible(true);
        $resultado = $m->invoke($controller, $archivo);
        @unlink($tmp);

        return $resultado;
    }

    public function test_la_subida_exige_extension_y_tipo_reales(): void
    {
        $this->assertNull($this->validarSubida('ok.pdf', "%PDF-1.4\n%%EOF"));
        // Script PHP con extensión .php: se rechaza aunque diga ser otra cosa.
        $this->assertNotNull($this->validarSubida('shell.php', '<?php echo 1;'));
        // HTML renombrado a .pdf: el contenido real no es PDF.
        $this->assertNotNull($this->validarSubida('falso.pdf', '<html><script>alert(1)</script></html>'));
        // PDF real con extensión peligrosa.
        $this->assertNotNull($this->validarSubida('doble.pdf.php', "%PDF-1.4\n%%EOF"));
    }

    public function test_comando_seguridad_aplicar_crea_columnas_y_es_repetible(): void
    {
        Schema::drop('users');
        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('email');
        });

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('seguridad:aplicar'));
        $this->assertTrue(Schema::hasColumn('users', 'intentos_fallidos'));
        $this->assertTrue(Schema::hasColumn('users', 'bloqueado_hasta'));

        // Segunda ejecución: no falla ni duplica columnas.
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('seguridad:aplicar'));
    }
}
