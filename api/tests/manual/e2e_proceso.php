<?php
/*
 * Prueba de punta a punta de las funciones nuevas, dentro de una transacción
 * que se revierte al final: la base queda igual y se borran los PDF que
 * genera. Usa los usuarios internos reales (ids 14 a 19). Con MAIL_MODO=smtp
 * los correos del flujo llegan a Mailpit (http://localhost:8025).
 *
 * Uso, desde la carpeta api-proveedores:
 *   php tests/manual/e2e_proceso.php .
 */
$base = $argv[1];
$app = require $base . '/bootstrap/app.php';

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

$ok = 0; $fail = 0;
function check($cond, $label, $extra = '') {
    global $ok, $fail;
    if ($cond) { $ok++; echo "PASS  $label\n"; }
    else { $fail++; echo "FAIL  $label" . ($extra ? "  -> $extra" : '') . "\n"; }
}
function api($method, $uri, $userId, $data = []) {
    global $app;
    $token = JWT::encode(['sub' => $userId, 'iat' => time(), 'exp' => time() + 600], env('JWT_SECRET'), 'HS256');
    $req = Request::create('/v1/' . $uri, $method, [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'e2e-test',
    ], json_encode($data));
    $res = $app->handle($req);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$ADMIN = 14; $PM = 16; $CONTRA = 17; $CRED = 18; $DM = 19;

$logPath = $base . '/storage/logs/correos_simulados.log';
$logSize = file_exists($logPath) ? filesize($logPath) : null;
$dirs = ['proveedor_declaraciones', 'proveedor_informes', 'proveedor_fichas'];
$antes = [];
foreach ($dirs as $d) { $antes[$d] = glob($base . "/storage/app/uploads/$d/*") ?: []; }

DB::beginTransaction();
try {
    // 1. Solicitud preliminar del PM
    [$s, $r] = api('POST', 'interno/proveedores/solicitud', $PM, [
        'emailProveedor' => 'demo@proveedor.test', 'empresaIds' => ['DIMERC', 'RHEIN'],
        'tipo' => 'Nacional', 'tipoProveedorDetalle' => 'Proveedor de productos nacional',
        'razonSocial' => 'Proveedor Demo Prueba SpA', 'rut' => '99.887.766-0', 'giro' => 'Venta de insumos',
    ]);
    check($s === 200 || $s === 201, 'PM crea solicitud preliminar', "$s " . json_encode($r));
    $pid = (int) DB::table('proveedores')->max('id');
    $docsPm = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->pluck('area_responsable', 'nombre')->toArray();
    check(($docsPm['Declaración Conflicto de Interés Solicitante'] ?? null) === 'contraloria' && isset($docsPm['Autorización de jefatura']), 'Catálogo genera los 2 documentos del PM con su área', json_encode($docsPm));

    // 2. Declaración de conflicto de interés
    [$s] = api('POST', "interno/proveedores/$pid/declaraciones/conflicto-interes", $CONTRA, ['acepta' => true]);
    check($s === 403, 'Contraloría no puede firmar la declaración del solicitante', "$s");
    [$s] = api('POST', "interno/proveedores/$pid/declaraciones/conflicto-interes", $PM, ['tiene_relacionados' => false]);
    check($s === 422, 'Firma sin aceptación es rechazada (422)', "$s");
    [$s, $r] = api('POST', "interno/proveedores/$pid/declaraciones/conflicto-interes", $PM, [
        'cargo' => 'Product Manager', 'empresa' => 'Dimerc', 'tiene_relacionados' => false, 'acepta' => true,
    ]);
    check($s === 200 && ($r['data']['integridadVerificada'] ?? false), 'PM firma conflicto de interés e integridad verificada', "$s " . json_encode($r));
    $doc = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('nombre', 'Declaración Conflicto de Interés Solicitante')->first();
    check($doc->estado === 'Subido' && $doc->archivo_url && app(App\Services\DocumentoStorageService::class)->resolver($doc->archivo_url) !== null, 'PDF de la declaración generado y documento en Subido');
    check(DB::table('notificaciones')->where('user_id', $CONTRA)->where('tipo', 'declaracion_firmada')->exists(), 'Contraloría notificada de la declaración firmada');

    // 3. Preparación del proveedor (simula autorización subida, invitación y activación)
    DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('nombre', 'Autorización de jefatura')->update(['estado' => 'Subido']);
    $provUser = DB::table('users')->insertGetId(['email' => 'demo@proveedor.test', 'display_name' => 'Representante Demo', 'role' => 'proveedor', 'auth_type' => 'local', 'active' => 1]);
    DB::table('proveedores')->where('id', $pid)->update(['proveedor_user_id' => $provUser]);
    app(App\Services\ProveedorDocumentosService::class)->crearDocumentosProveedor($pid, 'Proveedor de productos nacional');
    $now = date('Y-m-d H:i:s', time() - 5 * 86400);
    $areas = ['pm' => 'PM', 'contraloria' => 'Contraloría', 'legal_crediticia' => 'Legal Crediticia', 'legal_judicial' => 'Legal Judicial', 'datos_maestros' => 'Datos Maestros'];
    $i = 0;
    foreach ($areas as $a => $n) {
        $i++;
        $st = $a === 'pm' ? 'En revisión' : 'Pendiente';
        DB::table('proveedor_workflow_steps')->insert(['proveedor_id' => $pid, 'step_key' => $a, 'step_name' => $n, 'area' => $a, 'status' => $st, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('proveedor_validaciones')->insert(['proveedor_id' => $pid, 'area' => $a, 'area_name' => $n, 'status' => $st, 'created_at' => $now, 'updated_at' => $now]);
    }
    DB::table('proveedores')->where('id', $pid)->update(['created_at' => $now]);
    $docsProv = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->pluck('area_responsable', 'nombre')->toArray();
    check(($docsProv['Declaración Relacionados y PEP'] ?? null) === 'contraloria' && ($docsProv['Poder de Representante Legal'] ?? null) === 'legal_judicial' && ($docsProv['Referencia comercial'] ?? null) === 'legal_crediticia', 'Documentos del proveedor creados con su área', json_encode($docsProv));

    // 4. Declaración PEP del proveedor
    [$s] = api('POST', "proveedor/solicitudes/$pid/declaraciones/relacionados-pep", $provUser, ['es_pep' => true, 'peps' => [], 'declarante_rut' => '11.111.111-1', 'acepta_modelo_prevencion' => true, 'acepta' => true]);
    check($s === 422, 'PEP declarado sin detalle es rechazado (422)', "$s");
    [$s, $r] = api('POST', "proveedor/solicitudes/$pid/declaraciones/relacionados-pep", $provUser, [
        'declarante_rut' => '11.111.111-1', 'cargo' => 'Gerente General',
        'es_pep' => true, 'peps' => [['nombre' => 'Persona Ficticia', 'cargo' => 'Concejal', 'relacion' => 'Tío']],
        'acepta_modelo_prevencion' => true, 'acepta' => true,
    ]);
    check($s === 200 && ($r['data']['declaraPep'] ?? false), 'Proveedor firma declaración de relacionados y PEP', "$s " . json_encode($r));
    check(DB::table('notificaciones')->where('proveedor_id', $pid)->where('tipo', 'alerta_pep')->whereIn('user_id', [$CONTRA, $CRED])->count() === 2, 'Alerta PEP enviada a Contraloría y Legal Crediticia');
    [$s, $r] = api('GET', "proveedor/solicitudes/$pid/declaraciones", $provUser);
    check($s === 200 && count($r['data']) === 1 && $r['data'][0]['tipo'] === 'relacionados_pep', 'Proveedor ve solo su declaración, no la del solicitante');
    [$s, $r] = api('GET', "interno/proveedores", $CONTRA);
    $visibles = array_column($r['data'] ?? $r ?? [], 'id');
    check(in_array((string) $pid, array_map('strval', $visibles), true), 'Contraloría ve al proveedor (tiene documentos de su área)', "$s");

    // 5. Evaluación crediticia
    [$s] = api('POST', "interno/proveedores/$pid/evaluacion-crediticia", $CONTRA, ['dicom_resultado' => 'sin_morosidad', 'dicom_representante_resultado' => 'sin_morosidad']);
    check($s === 403, 'Contraloría no puede evaluar crédito (solo Legal Crediticia)', "$s");
    $payloadEval = ['fecha_inicio_actividades' => '2019-03-01', 'dicom_resultado' => 'sin_morosidad', 'dicom_representante_resultado' => 'sin_morosidad', 'observaciones' => 'Prueba'];
    [$s, $r] = api('POST', "interno/proveedores/$pid/evaluacion-crediticia", $CRED, $payloadEval);
    check($s === 200 && $r['data']['resultado'] === 'rojo', 'Semáforo ROJO con referencia comercial pendiente', "$s " . ($r['data']['resultado'] ?? json_encode($r)));
    DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('nombre', 'Referencia comercial')->update(['estado' => 'Aprobado']);
    [$s, $r] = api('POST', "interno/proveedores/$pid/evaluacion-crediticia", $CRED, $payloadEval);
    check($s === 200 && $r['data']['resultado'] === 'amarillo', 'Semáforo AMARILLO con PEP declarado y sin Gesintel', "$s " . ($r['data']['resultado'] ?? ''));
    $evalId = $r['data']['id'];
    $estados = array_column($r['data']['reglas'], 'estado', 'clave');
    check($estados['antiguedad'] === 'cumple' && $estados['referencia_comercial'] === 'cumple' && $estados['declaracion_pep'] === 'advertencia' && $estados['conflicto_interes'] === 'cumple', 'Reglas individuales calculadas correctamente', json_encode($estados));
    [$s] = api('POST', "interno/proveedores/$pid/evaluacion-crediticia", $CRED, ['fecha_inicio_actividades' => '2999-01-01', 'dicom_resultado' => 'x', 'dicom_representante_resultado' => 'sin_morosidad']);
    check($s === 422, 'Evaluación con datos inválidos rechazada (422)', "$s");

    // 5b. DICOM (en modo simulado fuera de la red de Dimerc)
    DB::table('proveedor_detalles')->where('proveedor_id', $pid)->update(['representante_legal' => 'Representante Demo', 'representante_ci' => '12.345.679-K']);
    [$s, $r] = api('POST', 'interno/proveedores/99887766-0/dicom/empresa', $CRED);
    [$s2, $r2] = api('POST', 'interno/proveedores/99887766-0/dicom/persona', $CRED);
    check($s === 200 && $s2 === 200, 'Legal Crediticia consulta DICOM de empresa y representante', "$s $s2 " . json_encode($r));
    $rutPersona = DB::table('proveedor_dicom_checks')->where('proveedor_id', $pid)->where('segmento', 'persona')->value('rut');
    check($rutPersona === '12345679K', 'La consulta persona usa el RUT del representante legal', 'rut=' . (string) $rutPersona . ' checks=' . json_encode(DB::table('proveedor_dicom_checks')->where('proveedor_id', $pid)->get(['endpoint', 'segmento', 'rut', 'http_code'])) . ' modo=' . env('DICOM_MODO'));
    [$s, $r] = api('POST', "interno/proveedores/$pid/evaluacion-crediticia", $CRED, ['fecha_inicio_actividades' => '2019-03-01', 'dicom_resultado' => 'no_consultado', 'dicom_representante_resultado' => 'no_consultado']);
    $reglasDicom = array_column($r['data']['reglas'] ?? [], null, 'clave');
    check(
        $s === 200 && $reglasDicom['dicom_empresa']['estado'] === 'cumple' && $reglasDicom['dicom_representante']['estado'] === 'no_cumple' && $r['data']['resultado'] === 'rojo',
        'Semáforo lee DICOM automáticamente (empresa sin morosidad, representante con morosidad → ROJO)',
        json_encode([$reglasDicom['dicom_empresa'] ?? null, $reglasDicom['dicom_representante'] ?? null])
    );
    check(strpos($reglasDicom['dicom_representante']['detalle'], 'simulado') !== false, 'El detalle indica que la consulta fue simulada');
    [$s, $r] = api('POST', "interno/proveedores/$pid/evaluacion-crediticia/$evalId/informe", $CRED);
    $url = $r['data']['archivoUrl'] ?? '';
    check($s === 200 && $url && app(App\Services\DocumentoStorageService::class)->resolver($url) && filesize(app(App\Services\DocumentoStorageService::class)->resolver($url)) > 2000, 'Informe PDF generado', "$s $url");
    $inf = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('nombre', 'Informe de evaluación crediticia')->first();
    check($inf && $inf->area_responsable === 'legal_crediticia' && (int) $inf->visible_para_proveedor === 0, 'Informe guardado como documento interno de Legal Crediticia');

    // 6. Validaciones por área y notificaciones
    [$s] = api('PUT', "validaciones/proveedores/$pid/areas/legal_crediticia", $CONTRA, ['status' => 'Aprobado']);
    check($s === 403, 'Contraloría no puede aprobar la etapa de Legal Crediticia', "$s");
    [$s] = api('PUT', "validaciones/proveedores/$pid/areas/pm", $PM, ['status' => 'Aprobado']);
    check($s === 200 && DB::table('notificaciones')->where('user_id', $CONTRA)->where('tipo', 'etapa_disponible')->exists(), 'PM aprueba y Contraloría recibe aviso de etapa disponible', "$s");
    [$s] = api('PUT', "validaciones/proveedores/$pid/areas/contraloria", $CONTRA, ['status' => 'Observado', 'comments' => 'Falta poder']);
    check($s === 200 && DB::table('notificaciones')->where('user_id', $PM)->where('tipo', 'validacion_observado')->exists(), 'Observación de Contraloría notifica al solicitante', "$s");
    [$s] = api('PUT', "validaciones/proveedores/$pid/areas/contraloria", $CONTRA, ['status' => 'Aprobado']);
    check($s === 200 && DB::table('notificaciones')->where('user_id', $CRED)->where('tipo', 'etapa_disponible')->exists(), 'Contraloría aprueba y Legal Crediticia recibe aviso', "$s");
    [$s] = api('PUT', "validaciones/proveedores/$pid/areas/legal_crediticia", $CRED, ['status' => 'Aprobado']);
    [$s2] = api('PUT', "validaciones/proveedores/$pid/areas/legal_judicial", $ADMIN, ['status' => 'Aprobado']);
    check($s === 200 && $s2 === 200, 'Legal Crediticia y Legal Judicial aprueban', "$s $s2");

    // 7. SAP simulado
    [$s, $r] = api('GET', "interno/proveedores/$pid/sap", $DM);
    check($s === 200 && $r['data']['motivoBloqueo'] !== null, 'SAP bloqueado mientras falta la aprobación de Datos Maestros', "$s");
    [$s] = api('PUT', "validaciones/proveedores/$pid/areas/datos_maestros", $DM, ['status' => 'Aprobado']);
    $estadoProv = DB::table('proveedores')->where('id', $pid)->value('estado');
    check($s === 200 && $estadoProv === 'Aprobado', 'Proveedor queda Aprobado con todas las áreas', "$s $estadoProv");
    check(DB::table('notificaciones')->where('proveedor_id', $pid)->where('tipo', 'proveedor_aprobado')->whereIn('user_id', [$PM, $provUser, $DM])->count() === 3, 'Aviso de aprobación a solicitante, proveedor y Datos Maestros');
    [$s] = api('POST', "interno/proveedores/$pid/sap/enviar", $CRED);
    check($s === 403, 'Legal Crediticia no puede enviar a SAP', "$s");
    [$s, $r] = api('POST', "interno/proveedores/$pid/sap/enviar", $DM);
    check($s === 200 && $r['data']['estado'] === 'observado', 'SAP responde OBSERVADO con datos faltantes', "$s " . ($r['data']['respuesta']['mensaje'] ?? json_encode($r)));
    DB::table('proveedor_detalles')->where('proveedor_id', $pid)->update(['direccion' => 'Av. Demo 123', 'comuna' => 'Santiago', 'banco_pago' => 'Banco Demo', 'cuenta_corriente' => '000123456', 'persona_contacto' => 'Contacto Demo', 'persona_contacto_email' => 'contacto@proveedor.test', 'plazo_pago' => '30 días']);
    // SAP no responde: el portal informa el error sin caerse y el envío se puede reintentar.
    putenv('SAP_MOCK_FORZAR_ERROR=true'); $_ENV['SAP_MOCK_FORZAR_ERROR'] = $_SERVER['SAP_MOCK_FORZAR_ERROR'] = 'true';
    [$s, $r] = api('POST', "interno/proveedores/$pid/sap/enviar", $DM);
    check($s === 200 && $r['data']['estado'] === 'error' && ($r['data']['respuesta']['codigo'] ?? '') === 'E-TIMEOUT' && empty($r['data']['numeroProveedorSap']), 'SAP sin respuesta (E-TIMEOUT): error controlado, sin número asignado', "$s " . json_encode($r['data'] ?? $r));
    putenv('SAP_MOCK_FORZAR_ERROR=false'); $_ENV['SAP_MOCK_FORZAR_ERROR'] = $_SERVER['SAP_MOCK_FORZAR_ERROR'] = 'false';
    [$s, $r] = api('POST', "interno/proveedores/$pid/sap/enviar", $DM);
    check($s === 200 && $r['data']['estado'] === 'exitoso' && str_starts_with((string) $r['data']['numeroProveedorSap'], 'MOCK'), 'SAP responde EXITOSO con número simulado', ($r['data']['numeroProveedorSap'] ?? json_encode($r)));
    [$s, $r] = api('POST', "interno/proveedores/$pid/sap/enviar", $DM);
    check($s === 200 && $r['data']['estado'] === 'error' && ($r['data']['respuesta']['codigo'] ?? '') === 'E-DUPLICADO', 'SAP responde ERROR por duplicado');

    // 8. Plazos y dashboard
    [$s, $r] = api('GET', "interno/proveedores/$pid/plazos", $ADMIN);
    $etapas = $r['data']['etapas'] ?? [];
    check($s === 200 && count($etapas) === 5 && $etapas[0]['fin'] !== null, 'Plazos por etapa calculados', "$s " . count($etapas));
    check($etapas[0]['vencida'] === false && $etapas[0]['dias'] >= 4.9, 'Etapa PM mide ~5 días (creada hace 5 días)', json_encode($etapas[0]));
    [$s, $r] = api('GET', 'dashboard', $ADMIN);
    check($s === 200 && isset($r['data']['plazos']['promedioTotalDias']), 'Dashboard entrega indicadores de plazos', json_encode($r['data']['plazos'] ?? null));
    [$s, $r] = api('GET', 'dashboard', $provUser);
    check($s === 200 && $r['data']['plazos'] === null, 'Proveedor no ve indicadores internos de plazos');

    // 9. Re-evaluación y comandos
    $svc = app(App\Services\EvaluacionCrediticiaService::class);
    $re = $svc->reevaluar(App\Models\Proveedores::find($pid));
    $claves = array_column($re->reglas(), 'estado', 'clave');
    check($re->origen === 'reevaluacion' && ($claves['vigencia_consultas'] ?? '') === 'advertencia', 'Re-evaluación agrega regla de vigencia de consultas', json_encode($claves));
    check(($claves['dicom_representante'] ?? '') === 'no_cumple' && strpos((string) $re->observaciones, '2 consulta(s) DICOM nuevas') !== false, 'Re-evaluación vuelve a consultar DICOM y detecta la morosidad', (string) $re->observaciones);
    [$s, $r] = api('POST', "interno/proveedores/$pid/evaluacion-crediticia/reevaluar", $CRED);
    check($s === 200 && $r['data']['origen'] === 'reevaluacion', 'Re-evaluación manual desde la API');

    // 10. Notificaciones
    [$s, $r] = api('GET', 'notificaciones', $CONTRA);
    check($s === 200 && $r['data']['noLeidas'] >= 3, 'Contraloría ve sus notificaciones sin leer', "$s " . ($r['data']['noLeidas'] ?? ''));
    [$s] = api('PUT', 'notificaciones/leer-todas', $CONTRA);
    [$s2, $r] = api('GET', 'notificaciones', $CONTRA);
    check($s === 200 && $r['data']['noLeidas'] === 0, 'Marcar todas como leídas');
    check(DB::table('notificaciones')->where('email_enviado', 1)->count() > 0, 'Correos de las notificaciones enviados');

    // 11. Comandos programados (al final: cargar la consola afecta a las peticiones siguientes)
    $app->make(Illuminate\Contracts\Console\Kernel::class);
    $code = Illuminate\Support\Facades\Artisan::call('plazos:revisar');
    check($code === 0, 'Comando plazos:revisar se ejecuta', trim(Illuminate\Support\Facades\Artisan::output()));
    $code = Illuminate\Support\Facades\Artisan::call('proveedores:reevaluar');
    check($code === 0, 'Comando proveedores:reevaluar se ejecuta', trim(Illuminate\Support\Facades\Artisan::output()));
} catch (\Throwable $e) {
    check(false, 'Excepción no controlada', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    while (DB::transactionLevel() > 0) { DB::rollBack(); }
    foreach ($dirs as $d) {
        foreach (array_diff(glob($base . "/storage/app/uploads/$d/*") ?: [], $antes[$d]) as $f) { @unlink($f); }
    }
    if ($logSize === null) { @unlink($logPath); } else { $h = fopen($logPath, 'r+'); ftruncate($h, $logSize); fclose($h); }
}

echo "\nResultado: $ok pasaron, $fail fallaron\n";
echo 'Proveedores en la base tras revertir: ' . DB::table('proveedores')->count() . "\n";
echo 'Notificaciones en la base tras revertir: ' . DB::table('notificaciones')->count() . "\n";
