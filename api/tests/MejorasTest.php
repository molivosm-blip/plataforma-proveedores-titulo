<?php

namespace Tests;

use App\Console\Commands\RecordarDocumentos;
use App\Models\User;
use App\Services\AuthService;
use App\Services\RecuperacionPasswordService;
use App\Services\RutService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| Mejoras de noviembre 2026: validación de RUT en el servidor, exportación
| a CSV, bitácora, recuperación de clave, recordatorios, vencimiento de
| documentos y rol auditor (solo lectura).
*/
class MejorasTest extends TestCase
{
    use EsquemaDePruebas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
    }

    /* ------------------------------- RUT ------------------------------- */

    public function test_rut_valido_e_invalido(): void
    {
        $rut = new RutService();

        $this->assertTrue($rut->esValido('76.123.456-0'));
        $this->assertTrue($rut->esValido('761234560'));
        $this->assertTrue($rut->esValido('12.345.678-5'));
        $this->assertFalse($rut->esValido('76.123.456-7'), 'dígito verificador incorrecto');
        $this->assertFalse($rut->esValido('abc'));
        $this->assertFalse($rut->esValido(''));
        $this->assertFalse($rut->esValido('1-9'), 'muy corto');
    }

    public function test_rut_con_k_y_normalizacion(): void
    {
        $rut = new RutService();

        $this->assertSame('76123456-0', $rut->normalizar('76.123.456-0'));
        $this->assertSame('K', $rut->digitoVerificador('12345670'));
        $this->assertTrue($rut->esValido('12.345.670-k'), 'K minúscula también vale');
        $this->assertFalse($rut->esValido('12.345.670-0'));
    }

    public function test_los_300_rut_de_demostracion_son_validos(): void
    {
        $archivo = __DIR__ . '/../database/demo/ruts_qa_normalizados.csv';
        $rut = new RutService();
        $revisados = 0;

        foreach (file($archivo, FILE_IGNORE_NEW_LINES) as $i => $linea) {
            if ($i === 0) {
                continue;
            }
            $columnas = str_getcsv($linea);
            $this->assertTrue($rut->esValido($columnas[1]), 'RUT inválido: ' . $columnas[1]);
            $revisados++;
        }

        $this->assertSame(300, $revisados);
    }

    public function test_la_api_rechaza_un_rut_invalido_en_la_solicitud_nacional(): void
    {
        $admin = $this->crearUsuario('pm@dimerc.cl', 'admin_portal');

        $res = $this->api('POST', 'interno/proveedores', $admin, [
            'razon_social' => 'X SpA', 'rut' => '76.123.456-7', 'tipo' => 'Nacional',
            'empresa_id' => 'DIMERC', 'giro' => 'Venta',
        ]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('RUT no es válido', json_decode($res->getContent(), true)['message']);
        $this->assertSame(0, DB::table('proveedores')->count(), 'no debe quedar nada guardado');
    }

    /* -------------------------- Exportación CSV -------------------------- */

    public function test_exportar_entrega_csv_con_bom_y_respeta_la_visibilidad(): void
    {
        $contra = $this->crearUsuario('contra@dimerc.cl', 'usuario_area', 'contraloria');
        $p1 = $this->crearProveedor(['razon_social' => 'Visible SpA']);
        $p2 = $this->crearProveedor(['razon_social' => 'Ajena SpA']);
        $this->crearDocumento($p1, ['area_responsable' => 'contraloria', 'estado' => 'Aprobado']);
        $this->crearDocumento($p2, ['area_responsable' => 'legal_judicial']);

        $res = $this->api('GET', 'interno/proveedores/exportar', $contra);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $cuerpo = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $cuerpo);
        $this->assertStringContainsString('Visible SpA', $cuerpo);
        $this->assertStringNotContainsString('Ajena SpA', $cuerpo);
    }

    public function test_exportar_neutraliza_formulas_de_excel(): void
    {
        $admin = $this->crearUsuario('admin@dimerc.cl', 'admin_portal');
        $this->crearProveedor(['razon_social' => '=HYPERLINK("http://malo")']);

        $cuerpo = $this->api('GET', 'interno/proveedores/exportar', $admin)->getContent();

        $this->assertStringContainsString('"\'=HYPERLINK', $cuerpo);
        $this->assertStringNotContainsString('";"=HYPERLINK', $cuerpo);
    }

    public function test_exportar_filtra_por_estado_y_se_niega_al_proveedor(): void
    {
        $admin = $this->crearUsuario('admin@dimerc.cl', 'admin_portal');
        $prov = $this->crearUsuario('prov@x.cl', 'proveedor');
        $this->crearProveedor(['razon_social' => 'Aprobada SpA', 'estado' => 'Aprobado']);
        $this->crearProveedor(['razon_social' => 'Revision SpA', 'estado' => 'En revisión']);

        $cuerpo = $this->api('GET', 'interno/proveedores/exportar?estado=Aprobado', $admin)->getContent();
        $this->assertStringContainsString('Aprobada SpA', $cuerpo);
        $this->assertStringNotContainsString('Revision SpA', $cuerpo);

        $this->assertSame(403, $this->api('GET', 'interno/proveedores/exportar', $prov)->getStatusCode());
        $this->assertSame(401, $this->api('GET', 'interno/proveedores/exportar')->getStatusCode());
    }

    /* ------------------------------ Bitácora ------------------------------ */

    public function test_bitacora_muestra_el_historial_solo_a_quien_ve_al_proveedor(): void
    {
        $contra = $this->crearUsuario('contra@dimerc.cl', 'usuario_area', 'contraloria');
        $legal = $this->crearUsuario('legal@dimerc.cl', 'usuario_area', 'legal_judicial');
        $p = $this->crearProveedor();
        $this->crearDocumento($p, ['area_responsable' => 'contraloria']);
        DB::table('proveedor_historial')->insert([
            ['proveedor_id' => $p, 'accion' => 'Primero', 'realizado_por' => 'Ana', 'created_at' => '2026-11-01 10:00:00'],
            ['proveedor_id' => $p, 'accion' => 'Segundo', 'realizado_por' => 'Luis', 'created_at' => '2026-11-02 10:00:00'],
        ]);

        $res = $this->api('GET', "interno/proveedores/$p/bitacora", $contra);
        $this->assertSame(200, $res->getStatusCode());
        $datos = json_decode($res->getContent(), true)['data'];
        $this->assertSame(2, $datos['total']);
        $this->assertSame('Segundo', $datos['eventos'][0]['accion'], 'lo más reciente primero');

        $this->assertSame(404, $this->api('GET', "interno/proveedores/$p/bitacora", $legal)->getStatusCode());
    }

    /* ------------------------- Recuperación de clave ------------------------- */

    private function tokenDeCorreoSimulado(): ?string
    {
        $log = storage_path('logs/correos_simulados.log');
        if (!is_file($log)) {
            return null;
        }
        preg_match_all('/restablecer-clave\?token=([0-9a-f]{64})/', file_get_contents($log), $m);

        return $m[1] ? end($m[1]) : null;
    }

    public function test_recuperacion_de_clave_de_punta_a_punta(): void
    {
        @unlink(storage_path('logs/correos_simulados.log'));
        $u = $this->crearUsuario('prov@empresa.cl', 'proveedor', null, [
            'password_hash' => app(AuthService::class)->hashPassword('Clave-Antigua-2026'),
            'intentos_fallidos' => 3,
        ]);

        $res = $this->api('POST', 'auth/olvide-password', null, ['email' => 'prov@empresa.cl']);
        $this->assertSame(200, $res->getStatusCode());

        $token = $this->tokenDeCorreoSimulado();
        $this->assertNotNull($token, 'el correo debe traer el enlace');
        $this->assertSame(1, DB::table('recuperacion_password')->count());
        $this->assertNotSame($token, DB::table('recuperacion_password')->value('token_hash'), 'solo se guarda el hash');

        $ok = $this->api('POST', 'auth/restablecer-password', null, ['token' => $token, 'password' => 'Clave-Nueva-2026x']);
        $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());

        $u->refresh();
        $this->assertTrue(app(AuthService::class)->passwordMatches('Clave-Nueva-2026x', $u->password_hash));
        $this->assertSame(0, (int) $u->intentos_fallidos);

        $reuso = $this->api('POST', 'auth/restablecer-password', null, ['token' => $token, 'password' => 'Otra-Clave-2026y']);
        $this->assertSame(422, $reuso->getStatusCode(), 'el enlace sirve una sola vez');
    }

    public function test_recuperacion_responde_igual_si_el_correo_no_existe_o_no_es_proveedor(): void
    {
        $this->crearUsuario('interno@dimerc.cl', 'admin_portal');

        $a = $this->api('POST', 'auth/olvide-password', null, ['email' => 'no-existe@x.cl']);
        $b = $this->api('POST', 'auth/olvide-password', null, ['email' => 'interno@dimerc.cl']);

        $this->assertSame(200, $a->getStatusCode());
        $this->assertSame($a->getContent(), $b->getContent());
        $this->assertSame(0, DB::table('recuperacion_password')->count());
    }

    public function test_recuperacion_rechaza_token_vencido_falso_y_clave_debil(): void
    {
        $u = $this->crearUsuario('prov@empresa.cl', 'proveedor');
        $token = str_repeat('a', 64);
        DB::table('recuperacion_password')->insert([
            'user_id' => $u->id, 'token_hash' => hash('sha256', $token),
            'expira_at' => date('Y-m-d H:i:s', time() - 60), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $vencido = $this->api('POST', 'auth/restablecer-password', null, ['token' => $token, 'password' => 'Clave-Nueva-2026x']);
        $this->assertSame(422, $vencido->getStatusCode());

        $falso = $this->api('POST', 'auth/restablecer-password', null, ['token' => str_repeat('b', 64), 'password' => 'Clave-Nueva-2026x']);
        $this->assertSame(422, $falso->getStatusCode());

        DB::table('recuperacion_password')->update(['expira_at' => date('Y-m-d H:i:s', time() + 600)]);
        $debil = $this->api('POST', 'auth/restablecer-password', null, ['token' => $token, 'password' => '1234']);
        $this->assertSame(422, $debil->getStatusCode(), 'la clave nueva debe cumplir la política');
        $this->assertNull(DB::table('recuperacion_password')->value('usado_at'), 'un intento fallido no gasta el enlace');
    }

    public function test_recuperacion_limita_las_solicitudes_por_hora(): void
    {
        $this->crearUsuario('prov@empresa.cl', 'proveedor');

        for ($i = 0; $i < 6; $i++) {
            $this->api('POST', 'auth/olvide-password', null, ['email' => 'prov@empresa.cl']);
        }

        $this->assertSame(3, DB::table('recuperacion_password')->count());
    }

    public function test_un_nuevo_enlace_anula_el_anterior(): void
    {
        $this->crearUsuario('prov@empresa.cl', 'proveedor');
        $servicio = app(RecuperacionPasswordService::class);

        $servicio->solicitar('prov@empresa.cl');
        $servicio->solicitar('prov@empresa.cl');

        $this->assertSame(1, DB::table('recuperacion_password')->whereNull('usado_at')->count());
    }

    /* ------------------------ Recordatorios y vencimientos ------------------------ */

    public function test_recordatorio_al_proveedor_con_documentos_pendientes(): void
    {
        $prov = $this->crearUsuario('prov@empresa.cl', 'proveedor');
        $p = $this->crearProveedor(['proveedor_user_id' => $prov->id]);
        $antiguo = date('Y-m-d H:i:s', time() - 5 * 86400);
        $this->crearDocumento($p, ['nombre' => 'Certificado bancario', 'updated_at' => $antiguo]);
        $this->crearDocumento($p, ['nombre' => 'Reciente', 'updated_at' => date('Y-m-d H:i:s')]);

        Artisan::call('proveedores:recordar');
        Artisan::call('proveedores:recordar'); // la segunda no repite

        $n = DB::table('notificaciones')->where('tipo', 'recordatorio_documentos')->get();
        $this->assertCount(1, $n);
        $this->assertStringContainsString('Certificado bancario', $n[0]->mensaje);
        $this->assertStringNotContainsString('Reciente', $n[0]->mensaje);
        $this->assertSame($prov->id, (int) $n[0]->user_id);
    }

    public function test_no_recuerda_documentos_que_sube_otra_area_ni_procesos_cerrados(): void
    {
        $prov = $this->crearUsuario('prov@empresa.cl', 'proveedor');
        $antiguo = date('Y-m-d H:i:s', time() - 9 * 86400);
        $abierto = $this->crearProveedor(['proveedor_user_id' => $prov->id]);
        $this->crearDocumento($abierto, ['responsable_carga' => 'pm', 'updated_at' => $antiguo]);
        $cerrado = $this->crearProveedor(['proveedor_user_id' => $prov->id, 'estado' => 'Aprobado']);
        $this->crearDocumento($cerrado, ['updated_at' => $antiguo]);

        Artisan::call('proveedores:recordar');

        $this->assertSame(0, DB::table('notificaciones')->count());
    }

    public function test_vencimientos_avisan_al_proveedor_y_al_area_una_vez(): void
    {
        $prov = $this->crearUsuario('prov@empresa.cl', 'proveedor');
        $contra = $this->crearUsuario('contra@dimerc.cl', 'usuario_area', 'contraloria');
        $p = $this->crearProveedor(['proveedor_user_id' => $prov->id]);
        $this->crearDocumento($p, ['nombre' => 'Vence pronto', 'estado' => 'Aprobado', 'fecha_vencimiento' => date('Y-m-d', time() + 10 * 86400)]);
        $this->crearDocumento($p, ['nombre' => 'Ya vencido', 'estado' => 'Aprobado', 'fecha_vencimiento' => date('Y-m-d', time() - 2 * 86400)]);
        $this->crearDocumento($p, ['nombre' => 'Lejano', 'estado' => 'Aprobado', 'fecha_vencimiento' => date('Y-m-d', time() + 200 * 86400)]);
        $this->crearDocumento($p, ['nombre' => 'No aprobado', 'estado' => 'Subido', 'fecha_vencimiento' => date('Y-m-d', time() - 2 * 86400)]);

        Artisan::call('documentos:vencimientos');
        Artisan::call('documentos:vencimientos');

        $this->assertSame(2, DB::table('notificaciones')->where('user_id', $prov->id)->count());
        $this->assertSame(2, DB::table('notificaciones')->where('user_id', $contra->id)->count());
        $this->assertSame(1, DB::table('notificaciones')->where('tipo', 'documento_vencido')->where('user_id', $prov->id)->count());
        $this->assertSame(0, DB::table('notificaciones')->where('mensaje', 'like', '%Lejano%')->count());
    }

    public function test_el_revisor_puede_fijar_la_fecha_de_vencimiento(): void
    {
        $contra = $this->crearUsuario('contra@dimerc.cl', 'usuario_area', 'contraloria');
        $prov = $this->crearUsuario('prov@empresa.cl', 'proveedor');
        $p = $this->crearProveedor(['proveedor_user_id' => $prov->id]);
        $d = $this->crearDocumento($p, ['estado' => 'Subido']);

        $res = $this->api('PUT', "interno/documentos/$d/estado", $contra, ['estado' => 'Aprobado', 'fechaVencimiento' => '2027-03-31']);

        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $this->assertSame('2027-03-31', DB::table('proveedor_documentos')->where('id', $d)->value('fecha_vencimiento'));
        $this->assertSame('2027-03-31', json_decode($res->getContent(), true)['data']['documento']['fechaVencimiento']);
    }

    public function test_aplicar_mejoras_es_repetible(): void
    {
        $this->assertSame(0, Artisan::call('mejoras:aplicar'));
        $this->assertSame(0, Artisan::call('mejoras:aplicar'));
    }

    /* ----------------------------- Rol auditor ----------------------------- */

    public function test_el_auditor_ve_todo_pero_no_puede_modificar(): void
    {
        $auditor = $this->crearUsuario('auditor@dimerc.cl', 'auditor');
        $p = $this->crearProveedor();
        $d = $this->crearDocumento($p, ['estado' => 'Subido']);

        $lista = $this->api('GET', 'interno/proveedores', $auditor);
        $this->assertSame(200, $lista->getStatusCode(), $lista->getContent());
        $this->assertCount(1, json_decode($lista->getContent(), true)['data']);
        $this->assertSame(200, $this->api('GET', "interno/proveedores/$p/bitacora", $auditor)->getStatusCode());
        $this->assertSame(200, $this->api('GET', 'interno/proveedores/exportar', $auditor)->getStatusCode());
        $this->assertSame(200, $this->api('GET', 'interno/documentos', $auditor)->getStatusCode());

        $escribir = $this->api('PUT', "interno/documentos/$d/estado", $auditor, ['estado' => 'Aprobado']);
        $this->assertSame(403, $escribir->getStatusCode());
        $this->assertSame('Subido', DB::table('proveedor_documentos')->where('id', $d)->value('estado'));

        $this->assertSame(403, $this->api('PUT', "interno/proveedores/$p/estado", $auditor, ['estado' => 'Aprobado'])->getStatusCode());
        $this->assertSame(403, $this->api('POST', "interno/proveedores/$p/ficha-pdf", $auditor)->getStatusCode());
    }

    public function test_el_auditor_no_puede_administrar_usuarios_ni_invitar(): void
    {
        $auditor = $this->crearUsuario('auditor@dimerc.cl', 'auditor');

        $this->assertSame(403, $this->api('GET', 'admin/usuarios', $auditor)->getStatusCode());
        $this->assertSame(403, $this->api('POST', 'interno/proveedor-invitations', $auditor, ['email' => 'a@b.cl', 'proveedorId' => 1])->getStatusCode());
    }

    public function test_la_cuenta_desactivada_pierde_el_acceso_aunque_el_token_sea_valido(): void
    {
        $u = $this->crearUsuario('contra@dimerc.cl', 'usuario_area', 'contraloria');
        $token = $this->tokenDe($u);

        $this->assertSame(200, $this->api('GET', 'dashboard', null, [], $token)->getStatusCode());

        DB::table('users')->where('id', $u->id)->update(['active' => 0]);

        $this->assertSame(401, $this->api('GET', 'dashboard', null, [], $token)->getStatusCode());
    }
}
