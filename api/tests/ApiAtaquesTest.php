<?php

namespace Tests;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;

/*
| Ataques directos a la API, sin pasar por la interfaz.
|
| Las dos primeras pruebas recorren TODAS las rutas del portal (no una
| muestra): cada ruta protegida debe rechazar a quien no trae un token
| válido, y cada ruta restringida por rol debe rechazar a los roles que
| no le corresponden. Si alguien agrega una ruta nueva sin protección,
| estas pruebas fallan.
*/
class ApiAtaquesTest extends TestCase
{
    use EsquemaDePruebas;

    /** Rutas públicas a propósito (login, activación, catálogos, salud). */
    private const PUBLICAS = [
        'GET/', 'GET/health/database',
        'POST/v1/auth/login', 'POST/v1/auth/msal',
        'POST/v1/auth/olvide-password', 'POST/v1/auth/restablecer-password',
        'GET/v1/empresas', 'GET/v1/empresas/{id}/documentos-requeridos',
        'GET/v1/catalogos/proveedores', 'GET/v1/catalogos/grupos-compra',
        'GET/v1/proveedor/invitations/{token}', 'POST/v1/proveedor/activar',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        putenv('APP_DEBUG=false');
    }

    /** @return array<string, array{method:string, uri:string, roles:?array}> */
    private function rutas(): array
    {
        $salida = [];

        foreach ($this->app->router->getRoutes() as $clave => $ruta) {
            $roles = null;
            $protegida = false;

            foreach ($ruta['action']['middleware'] ?? [] as $mw) {
                if ($mw === 'jwt') {
                    $protegida = true;
                }
                if (strpos($mw, 'role:') === 0) {
                    $roles = explode(',', substr($mw, 5));
                }
            }

            $salida[$clave] = [
                'method' => $ruta['method'],
                'uri' => $ruta['uri'],
                'roles' => $roles,
                'protegida' => $protegida,
            ];
        }

        return $salida;
    }

    private function concreta(string $uri): string
    {
        return preg_replace('/\{[^}]+\}/', '1', $uri);
    }

    private function llamar(string $metodo, string $uri, ?string $token)
    {
        $headers = $token !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] : [];
        $this->call($metodo, $uri, [], [], [], $headers);

        return $this->response;
    }

    public function test_el_portal_tiene_las_rutas_que_se_esperan(): void
    {
        $rutas = $this->rutas();

        $this->assertGreaterThan(70, count($rutas));

        foreach (self::PUBLICAS as $clave) {
            $this->assertArrayHasKey($clave, $rutas, "ruta pública no encontrada: $clave");
        }
    }

    public function test_toda_ruta_protegida_rechaza_peticiones_sin_token_valido(): void
    {
        $usuario = $this->crearUsuario('admin@dimerc.cl', 'admin_portal');
        $inactivoDeOtroSecreto = JWT::encode(['sub' => $usuario->id, 'exp' => time() + 600], str_repeat('x', 40), 'HS256');
        $vencido = JWT::encode(['sub' => $usuario->id, 'iat' => time() - 7200, 'exp' => time() - 3600], env('JWT_SECRET'), 'HS256');
        $usuarioInexistente = JWT::encode(['sub' => 99999, 'exp' => time() + 600], env('JWT_SECRET'), 'HS256');
        $sinFirma = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.'
            . rtrim(strtr(base64_encode('{"sub":' . $usuario->id . ',"exp":' . (time() + 600) . '}'), '+/', '-_'), '=') . '.';

        $intentos = [
            'sin token' => null,
            'token basura' => 'esto-no-es-un-token',
            'firmado con otra clave' => $inactivoDeOtroSecreto,
            'token vencido' => $vencido,
            'usuario que no existe' => $usuarioInexistente,
            'algoritmo none (sin firma)' => $sinFirma,
        ];

        $revisadas = 0;

        foreach ($this->rutas() as $clave => $r) {
            if (in_array($clave, self::PUBLICAS, true)) {
                continue;
            }

            $this->assertTrue($r['protegida'], "La ruta $clave no exige token (middleware jwt)");

            foreach ($intentos as $nombre => $token) {
                $res = $this->llamar($r['method'], $this->concreta($r['uri']), $token);
                $this->assertSame(401, $res->getStatusCode(), "$clave con $nombre respondió {$res->getStatusCode()}");
            }

            $revisadas++;
        }

        $this->assertGreaterThan(60, $revisadas);
    }

    public function test_cada_ruta_restringida_por_rol_rechaza_a_los_roles_que_no_le_corresponden(): void
    {
        $todos = ['proveedor', 'pm_user', 'pm_admin', 'admin_portal', 'admin_area', 'usuario_area', 'auditor'];
        $usuarios = [];
        foreach ($todos as $rol) {
            $usuarios[$rol] = $this->crearUsuario("$rol@prueba.cl", $rol, in_array($rol, ['admin_area', 'usuario_area', 'pm_user'], true) ? 'contraloria' : null);
        }

        $comprobaciones = 0;

        foreach ($this->rutas() as $clave => $r) {
            if (!$r['protegida'] || $r['roles'] === null) {
                continue;
            }

            foreach ($todos as $rol) {
                if (in_array($rol, $r['roles'], true)) {
                    continue;
                }

                $res = $this->llamar($r['method'], $this->concreta($r['uri']), $this->tokenDe($usuarios[$rol]));
                $this->assertSame(403, $res->getStatusCode(), "El rol $rol no debía entrar a $clave (respondió {$res->getStatusCode()})");
                $comprobaciones++;
            }
        }

        $this->assertGreaterThan(80, $comprobaciones);
    }

    public function test_el_auditor_no_puede_usar_ningun_metodo_que_modifique_datos(): void
    {
        $auditor = $this->crearUsuario('auditor@dimerc.cl', 'auditor');
        $token = $this->tokenDe($auditor);
        $bloqueadas = 0;

        foreach ($this->rutas() as $clave => $r) {
            if (!$r['protegida'] || in_array($r['method'], ['GET', 'HEAD'], true)) {
                continue;
            }

            $res = $this->llamar($r['method'], $this->concreta($r['uri']), $token);
            $this->assertSame(403, $res->getStatusCode(), "El auditor pudo llegar a $clave ({$res->getStatusCode()})");
            $bloqueadas++;
        }

        $this->assertGreaterThan(25, $bloqueadas);
    }

    public function test_un_proveedor_no_ve_ni_modifica_lo_de_otro_proveedor(): void
    {
        $a = $this->crearUsuario('a@prov.cl', 'proveedor');
        $b = $this->crearUsuario('b@prov.cl', 'proveedor');
        $pa = $this->crearProveedor(['proveedor_user_id' => $a->id, 'razon_social' => 'Proveedor A']);
        $pb = $this->crearProveedor(['proveedor_user_id' => $b->id, 'razon_social' => 'Proveedor B']);
        $docB = $this->crearDocumento($pb);

        $lista = $this->api('GET', 'proveedor/solicitudes', $a);
        $this->assertStringContainsString('Proveedor A', $lista->getContent());
        $this->assertStringNotContainsString('Proveedor B', $lista->getContent());

        $this->assertContains($this->api('GET', "proveedor/solicitudes/$pb/declaraciones", $a)->getStatusCode(), [403, 404]);
        $this->assertContains($this->api('GET', "documentos/$docB/archivo", $a)->getStatusCode(), [403, 404]);
        $this->assertContains($this->api('POST', "documentos/$docB/upload", $a)->getStatusCode(), [403, 404, 422]);
        $this->assertSame('Pendiente', DB::table('proveedor_documentos')->where('id', $docB)->value('estado'));
    }

    public function test_un_usuario_de_area_no_actua_sobre_lo_de_otra_area(): void
    {
        $legal = $this->crearUsuario('legal@dimerc.cl', 'usuario_area', 'legal_judicial');
        $p = $this->crearProveedor();
        $doc = $this->crearDocumento($p, ['area_responsable' => 'contraloria', 'estado' => 'Subido']);

        $res = $this->api('PUT', "interno/documentos/$doc/estado", $legal, ['estado' => 'Aprobado']);

        $this->assertContains($res->getStatusCode(), [403, 404]);
        $this->assertSame('Subido', DB::table('proveedor_documentos')->where('id', $doc)->value('estado'));
        $this->assertContains($this->api('GET', "interno/proveedores/$p/bitacora", $legal)->getStatusCode(), [403, 404]);
    }

    public function test_inyeccion_sql_en_el_login_y_en_los_parametros_no_rompe_nada(): void
    {
        $this->crearUsuario('prov@x.cl', 'proveedor');
        $admin = $this->crearUsuario('admin@dimerc.cl', 'admin_portal');

        $login = $this->api('POST', 'auth/login', null, ['email' => "prov@x.cl' OR '1'='1", 'password' => "' OR '1'='1"]);
        $this->assertContains($login->getStatusCode(), [401, 422]);
        $this->assertStringNotContainsString('token', strtolower($login->getContent()));

        $login2 = $this->api('POST', 'auth/login', null, ['email' => 'prov@x.cl', 'password' => "' OR '1'='1' --"]);
        $this->assertSame(401, $login2->getStatusCode());

        foreach (["1 OR 1=1", "1; DROP TABLE users", "' UNION SELECT * FROM users --"] as $malo) {
            $res = $this->api('GET', 'interno/proveedores/' . rawurlencode($malo) . '/bitacora', $admin);
            $this->assertContains($res->getStatusCode(), [404, 422], "respondió {$res->getStatusCode()} con: $malo");
            $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        }

        $this->assertSame(2, DB::table('users')->count(), 'la tabla de usuarios sigue intacta');
    }

    public function test_peticiones_mal_formadas_no_producen_errores_internos(): void
    {
        $admin = $this->crearUsuario('admin@dimerc.cl', 'admin_portal');

        $sinCuerpo = $this->api('POST', 'interno/proveedores/solicitud', $admin, []);
        $this->assertContains($sinCuerpo->getStatusCode(), [400, 422]);

        $tipoRaro = $this->api('POST', 'auth/login', null, ['email' => ['a' => 'b'], 'password' => ['x']]);
        $this->assertContains($tipoRaro->getStatusCode(), [401, 422]);

        $enorme = $this->api('POST', 'auth/login', null, ['email' => 'a@b.cl', 'password' => str_repeat('A', 200000)]);
        $this->assertLessThan(500, $enorme->getStatusCode());
    }

    public function test_el_login_no_revela_si_el_correo_existe(): void
    {
        $this->crearUsuario('existe@x.cl', 'proveedor', null, [
            'password_hash' => app(\App\Services\AuthService::class)->hashPassword('Clave-Valida-2026'),
        ]);

        $a = $this->api('POST', 'auth/login', null, ['email' => 'existe@x.cl', 'password' => 'incorrecta']);
        $b = $this->api('POST', 'auth/login', null, ['email' => 'noexiste@x.cl', 'password' => 'incorrecta']);

        $this->assertSame($a->getStatusCode(), $b->getStatusCode());
        $this->assertSame(json_decode($a->getContent(), true)['message'], json_decode($b->getContent(), true)['message']);
    }

    public function test_un_proveedor_no_puede_elevar_su_rol_cambiando_datos_de_su_cuenta(): void
    {
        $prov = $this->crearUsuario('prov@x.cl', 'proveedor', null, [
            'password_hash' => app(\App\Services\AuthService::class)->hashPassword('Clave-Valida-2026'),
        ]);

        $this->api('PUT', 'cuenta/password', $prov, [
            'actual' => 'Clave-Valida-2026', 'nueva' => 'Otra-Clave-Valida-2027',
            'role' => 'admin_portal', 'area' => 'contraloria', 'active' => 1,
        ]);

        $prov->refresh();
        $this->assertSame('proveedor', $prov->role);
        $this->assertNull($prov->area);
    }

    public function test_la_ruta_de_salud_no_muestra_el_nombre_de_la_base_de_datos(): void
    {
        $res = $this->call('GET', '/health/database');
        $contenido = $this->response->getContent();

        $this->assertStringNotContainsString('portal_proveedores', $contenido);
        $this->assertStringNotContainsString('SQLSTATE', $contenido);
    }
}
