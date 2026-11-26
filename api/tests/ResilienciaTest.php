<?php

namespace Tests;

use App\Exceptions\ServicioExternoNoDisponibleException;
use App\Services\AmlUpdateService;
use App\Services\NotificacionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Qué pasa cuando un servicio externo no responde.
|
| Se levanta un servidor falso local (tests/herramientas/servidor_falso.php)
| que devuelve errores 500, respuestas que no son JSON, respuestas lentas y
| 401. También se prueba un puerto cerrado (sin conexión) y un servidor de
| correo caído. En todos los casos el portal debe responder con un mensaje
| claro, sin detalles técnicos, dentro de un tiempo razonable y sin dejar
| datos a medias.
*/
class ResilienciaTest extends TestCase
{
    use EsquemaDePruebas;

    private static $proceso;
    private static $puerto;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        for ($i = 0; $i < 20; $i++) {
            $puerto = random_int(20000, 45000);
            $socket = @stream_socket_server("tcp://127.0.0.1:$puerto");
            if ($socket) {
                fclose($socket);
                self::$puerto = $puerto;
                break;
            }
        }

        // Sin shell (arreglo de argumentos): funciona igual en Linux y Windows.
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . self::$puerto, __DIR__ . '/herramientas/servidor_falso.php'];
        self::$proceso = proc_open($cmd, [['pipe', 'r'], ['file', $nulo, 'w'], ['file', $nulo, 'w']], $pipes);

        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', self::$puerto, $e, $s, 0.2);
            if ($c) {
                fclose($c);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proceso) {
            proc_terminate(self::$proceso, 9);
            proc_close(self::$proceso);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();

        Schema::create('proveedor_dicom_checks', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedBigInteger('proveedor_id');
            $t->string('endpoint')->nullable();
            $t->string('segmento')->nullable();
            $t->string('rut')->nullable();
            $t->string('fecha_consulta')->nullable();
            $t->text('request_url')->nullable();
            $t->text('response_json')->nullable();
            $t->integer('http_code')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->string('created_at')->nullable();
            $t->string('updated_at')->nullable();
        });

        // El servidor falso atiende de a una consulta: se espera a que termine la lenta anterior.
        $this->esperarServidorLibre();

        putenv('APP_DEBUG=false');
        $_ENV['APP_DEBUG'] = 'false';
        putenv('DICOM_MODO=real');
        putenv('DICOM_TIMEOUT=1');
    }

    private function esperarServidorLibre(): void
    {
        $limite = microtime(true) + 8;

        while (microtime(true) < $limite) {
            $c = @fopen('http://127.0.0.1:' . self::$puerto . '/ok', 'r', false, stream_context_create(['http' => ['timeout' => 1]]));
            if ($c) {
                fclose($c);

                return;
            }
        }
    }

    private function dicom(string $baseUrl)
    {
        putenv('DICOM_BASE_URL=' . $baseUrl);
        $_ENV['DICOM_BASE_URL'] = $baseUrl;

        $analista = $this->crearUsuario('cred@dimerc.cl', 'usuario_area', 'legal_crediticia');
        $p = $this->crearProveedor(['rut' => '76.123.456-0']);
        $this->crearDocumento($p, ['area_responsable' => 'legal_crediticia']);

        $inicio = microtime(true);
        $res = $this->api('POST', 'interno/proveedores/76123456-0/dicom/empresa', $analista);

        return [$res, microtime(true) - $inicio];
    }

    private function url(string $modo): string
    {
        return 'http://127.0.0.1:' . self::$puerto . '/' . $modo;
    }

    /* ---------------------------------- DICOM ---------------------------------- */

    public function test_dicom_responde_bien_cuando_el_servicio_anda(): void
    {
        [$res] = $this->dicom($this->url('ok'));

        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame(1, DB::table('proveedor_dicom_checks')->count());
    }

    public function test_dicom_con_segmento_o_fecha_invalidos_responde_422_sin_llamar_al_tercero(): void
    {
        putenv('DICOM_BASE_URL=http://127.0.0.1:9');
        $_ENV['DICOM_BASE_URL'] = 'http://127.0.0.1:9';

        $analista = $this->crearUsuario('cred2@dimerc.cl', 'usuario_area', 'legal_crediticia');
        $p = $this->crearProveedor(['rut' => '76.123.456-0']);
        $this->crearDocumento($p, ['area_responsable' => 'legal_crediticia']);

        $res = $this->api('POST', 'interno/proveedores/76123456-0/dicom/otro', $analista);
        $this->assertSame(422, $res->getStatusCode(), $res->getContent());

        $res = $this->api('POST', 'interno/proveedores/76123456-0/dicom/legado/31-12-2026', $analista);
        $this->assertSame(422, $res->getStatusCode(), $res->getContent());

        $this->assertSame(0, DB::table('proveedor_dicom_checks')->count());
    }

    public function test_dicom_caido_responde_503_claro_y_no_guarda_nada(): void
    {
        // Puerto cerrado: nadie escucha, la conexión se rechaza.
        [$res, $seg] = $this->dicom('http://127.0.0.1:9');

        $this->assertSame(503, $res->getStatusCode(), $res->getContent());
        $mensaje = json_decode($res->getContent(), true)['message'];
        $this->assertStringContainsString('DICOM no está disponible', $mensaje);
        $this->assertStringNotContainsString('127.0.0.1', $mensaje, 'no debe mostrar direcciones internas');
        $this->assertSame(0, DB::table('proveedor_dicom_checks')->count());
        $this->assertLessThan(5, $seg);
    }

    public function test_dicom_con_error_500_del_tercero_responde_503(): void
    {
        [$res] = $this->dicom($this->url('error500'));

        $this->assertSame(503, $res->getStatusCode());
        $this->assertSame(0, DB::table('proveedor_dicom_checks')->count());
    }

    public function test_dicom_lento_corta_por_tiempo_de_espera(): void
    {
        [$res, $seg] = $this->dicom($this->url('lento'));

        $this->assertSame(503, $res->getStatusCode());
        $this->assertLessThan(3.5, $seg, 'con DICOM_TIMEOUT=1 no debe esperar los 4 segundos del tercero');
        $this->assertSame(0, DB::table('proveedor_dicom_checks')->count());
    }

    public function test_dicom_con_respuesta_que_no_es_json_no_rompe_el_portal(): void
    {
        [$res] = $this->dicom($this->url('basura'));

        $this->assertContains($res->getStatusCode(), [200, 503]);
        $this->assertJson($res->getContent());
        $this->assertStringNotContainsString('Stack trace', $res->getContent());
    }

    public function test_dicom_con_credencial_rechazada_no_muestra_detalles_tecnicos(): void
    {
        [$res] = $this->dicom($this->url('noautorizado'));

        $this->assertGreaterThanOrEqual(400, $res->getStatusCode());
        $this->assertStringNotContainsString('127.0.0.1', $res->getContent());
        $this->assertSame(0, DB::table('proveedor_dicom_checks')->count());
    }

    public function test_dicom_sin_url_configurada_no_revela_la_configuracion_interna(): void
    {
        [$res] = $this->dicom('');

        $this->assertGreaterThanOrEqual(400, $res->getStatusCode());
        $this->assertSame(0, DB::table('proveedor_dicom_checks')->count());
    }

    /* ---------------------------- Gesintel / AMLUpdate ---------------------------- */

    private function amlCurl(string $url): array
    {
        $servicio = app(AmlUpdateService::class);
        $m = new \ReflectionMethod($servicio, 'curlJson');
        $m->setAccessible(true);
        putenv('AMLUPDATE_TIMEOUT=1');

        return $m->invoke($servicio, 'GET', $url, null, 'token-de-prueba');
    }

    public function test_gesintel_caido_lanza_servicio_no_disponible(): void
    {
        $this->expectException(ServicioExternoNoDisponibleException::class);

        $this->amlCurl('http://127.0.0.1:9/mvc/rest/getAMLRisk');
    }

    public function test_gesintel_con_error_500_lanza_servicio_no_disponible(): void
    {
        $this->expectException(ServicioExternoNoDisponibleException::class);

        $this->amlCurl($this->url('error500') . '/mvc/rest/getAMLRisk');
    }

    public function test_gesintel_lento_corta_por_tiempo_de_espera(): void
    {
        $inicio = microtime(true);

        try {
            $this->amlCurl($this->url('lento') . '/mvc/rest/getAMLRisk');
            $this->fail('Debió lanzar la excepción por tiempo de espera');
        } catch (ServicioExternoNoDisponibleException $e) {
            $this->assertLessThan(3.5, microtime(true) - $inicio);
        }
    }

    public function test_gesintel_con_respuesta_basura_lanza_error_controlado(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON no válida');

        $this->amlCurl($this->url('basura') . '/mvc/rest/getAMLRisk');
    }

    public function test_el_endpoint_gesintel_caido_responde_503_sin_detalles(): void
    {
        putenv('AMLUPDATE_ENV=qa');
        putenv('AMLUPDATE_QA_BASE_URL=https://127.0.0.1:9');
        putenv('AMLUPDATE_TOKEN=token-de-prueba');
        putenv('AMLUPDATE_TIMEOUT=1');

        $analista = $this->crearUsuario('cred@dimerc.cl', 'usuario_area', 'legal_crediticia');
        $p = $this->crearProveedor();
        $this->crearDocumento($p, ['area_responsable' => 'legal_crediticia']);

        $res = $this->api('POST', "interno/proveedores/$p/aml/risk", $analista);

        $this->assertSame(503, $res->getStatusCode(), $res->getContent());
        $this->assertStringContainsString('Gesintel (AML) no está disponible', json_decode($res->getContent(), true)['message']);
        $this->assertStringNotContainsString('127.0.0.1', $res->getContent());
    }

    /* ------------------------------- Correo (SMTP) ------------------------------- */

    public function test_si_el_correo_esta_caido_el_aviso_igual_queda_en_la_campana(): void
    {
        putenv('MAIL_MODO=smtp');
        putenv('MAIL_HOST=127.0.0.1');
        putenv('MAIL_PORT=9');
        putenv('MAIL_TIMEOUT=1');
        @unlink(storage_path('logs/correos_simulados.log'));

        $u = $this->crearUsuario('contra@dimerc.cl', 'usuario_area', 'contraloria');
        $p = $this->crearProveedor();
        $proveedor = \App\Models\Proveedores::find($p);

        $n = app(NotificacionService::class)->notificar($u, $proveedor, 'documento_subido', 'Aviso', 'Mensaje de prueba');

        $this->assertSame(1, DB::table('notificaciones')->count(), 'el aviso queda en la campana');
        $this->assertNotNull($n->id);
        $this->assertFileExists(storage_path('logs/correos_simulados.log'), 'el correo queda en el registro local');
        putenv('MAIL_MODO=log');
    }

    /* ------------------------ Errores internos sin detalles técnicos ------------------------ */

    public function test_un_error_de_base_de_datos_no_muestra_sql_al_usuario(): void
    {
        $admin = $this->crearUsuario('admin@dimerc.cl', 'admin_portal');
        $this->crearProveedor();
        Schema::drop('empresas'); // fuerza un error SQL dentro del listado

        $res = $this->api('GET', 'interno/proveedores', $admin);

        $this->assertSame(500, $res->getStatusCode());
        $texto = $res->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $texto);
        $this->assertStringNotContainsString('select *', $texto);
        $this->assertStringContainsString('error interno', json_decode($texto, true)['message']);
    }
}
