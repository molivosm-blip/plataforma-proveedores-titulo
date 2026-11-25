<?php
/*
 * Datos de demostración: carga proveedores en cada fase del proceso, usando
 * la propia API (los mismos endpoints que usa el frontend), para que las
 * pantallas tengan casos reales que mostrar. A diferencia de e2e_proceso.php,
 * NO revierte: los datos quedan en la base.
 *
 * Uso, desde la carpeta api-proveedores:
 *   php tests/manual/seed_demo.php .              crea los proveedores de demostración
 *   php tests/manual/seed_demo.php . --mailpit    igual, pero envía los correos a Mailpit
 *   php tests/manual/seed_demo.php . --limpiar    borra solo lo que creó este script
 *   php tests/manual/seed_demo.php . --reclave    pone la clave actual a los usuarios demo ya creados
 *                                                 y les quita cualquier bloqueo de login
 *   php tests/manual/seed_demo.php . --cantidad=N  total de proveedores de demostración (por defecto 100:
 *                                                 los 21 casos descritos más los que se generan hasta llegar a N)
 *   php tests/manual/seed_demo.php . --paquete    genera PDF de ejemplo con los documentos que debe entregar
 *                                                 cada tipo de proveedor (storage/app/demo_documentos_para_subir)
 *
 * Por defecto los correos se registran en storage/logs/correos_simulados.log
 * (MAIL_MODO=log) para que el script no dependa de Mailpit ni se demore.
 * Se puede ejecutar más de una vez: omite los proveedores que ya existen.
 *
 * Todos los datos son ficticios. Los RUT salen del archivo
 * database/demo/ruts_qa_normalizados.csv (lista de pruebas de QA).
 */

$base = isset($argv[1]) ? rtrim($argv[1], '/\\') : '.';
$flags = array_slice($argv, 2);
$limpiar = in_array('--limpiar', $flags, true);
$conMailpit = in_array('--mailpit', $flags, true);
$reclave = in_array('--reclave', $flags, true);
$paquete = in_array('--paquete', $flags, true);
$cantidadTotal = 100;
foreach ($flags as $f) {
    if (preg_match('/^--cantidad=(\d+)$/', $f, $m)) { $cantidadTotal = (int) $m[1]; }
}

if (!$conMailpit) {
    putenv('MAIL_MODO=log');
    $_ENV['MAIL_MODO'] = 'log';
    $_SERVER['MAIL_MODO'] = 'log';
}

$app = require $base . '/bootstrap/app.php';

use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

const DOMINIO_DEMO = 'demo-proveedores.test';
// Cumple la política de contraseñas (mínimo 10 caracteres, letras y números).
const CLAVE_DEMO = 'Demo2026Portal!';

/* --------------------------------------------------------------------------
 | Utilidades
 * ------------------------------------------------------------------------ */

function api($method, $uri, $userId, $data = [])
{
    global $app;
    $headers = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'seed-demo',
    ];
    if ($userId !== null) {
        $token = JWT::encode(['sub' => $userId, 'iat' => time(), 'exp' => time() + 600], env('JWT_SECRET'), 'HS256');
        $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    $req = Request::create('/v1/' . $uri, $method, [], [], [], $headers, json_encode($data));
    $res = $app->handle($req);
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

function exigir($status, $resp, $etiqueta)
{
    if ($status < 200 || $status >= 300) {
        $msg = is_array($resp) ? ($resp['message'] ?? json_encode($resp, JSON_UNESCAPED_UNICODE)) : (string) $resp;
        throw new RuntimeException("$etiqueta -> HTTP $status: $msg");
    }
    return $resp;
}

function llamar($etiqueta, $method, $uri, $userId, $data = [])
{
    [$s, $r] = api($method, $uri, $userId, $data);
    return exigir($s, $r, $etiqueta);
}

function hace($dias)
{
    return date('Y-m-d H:i:s', time() - (int) round($dias * 86400));
}

function rutSinFormato($rut)
{
    return strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
}

function rutParaApi($rut)
{
    $limpio = rutSinFormato($rut);
    return substr($limpio, 0, -1) . '-' . substr($limpio, -1);
}

function ultimoDigitoCuerpo($rut)
{
    $limpio = rutSinFormato($rut);
    return (int) substr(substr($limpio, 0, -1), -1);
}

/* --------------------------------------------------------------------------
 | Usuarios internos (se buscan por rol y área, no por id)
 * ------------------------------------------------------------------------ */

$USR = [
    'admin' => DB::table('users')->where('role', 'admin_portal')->where('active', 1)->value('id'),
    'pm' => DB::table('users')->where('role', 'pm_user')->where('active', 1)->value('id'),
    'contraloria' => DB::table('users')->where('area', 'contraloria')->where('active', 1)->value('id'),
    'legal_crediticia' => DB::table('users')->where('area', 'legal_crediticia')->where('active', 1)->value('id'),
    'legal_judicial' => DB::table('users')->where('area', 'legal_judicial')->where('active', 1)->value('id'),
    'datos_maestros' => DB::table('users')->where('area', 'datos_maestros')->where('active', 1)->value('id'),
];
if (!$USR['legal_judicial']) {
    $USR['legal_judicial'] = $USR['admin']; // sin usuario de Legal Judicial, el administrador valida la etapa
}
foreach (['admin', 'pm', 'contraloria', 'legal_crediticia', 'datos_maestros'] as $k) {
    if (!$USR[$k]) {
        fwrite(STDERR, "No se encontró el usuario interno '$k' en la base. Revisa la tabla users.\n");
        exit(1);
    }
}

/* --------------------------------------------------------------------------
 | RUT de la lista de QA
 * ------------------------------------------------------------------------ */

$pool = [];
$csv = $base . '/database/demo/ruts_qa_normalizados.csv';
if (!is_file($csv)) {
    fwrite(STDERR, "Falta $csv\n");
    exit(1);
}
$h = fopen($csv, 'r');
$primera = true;
while (($fila = fgetcsv($h)) !== false) {
    if ($primera) { $primera = false; continue; }
    if (count($fila) < 4 || trim($fila[1]) === '') { continue; }
    $pool[] = ['rut' => trim($fila[1]), 'lista' => trim($fila[0])];
}
fclose($h);

$usados = array_map('rutSinFormato', DB::table('proveedores')->pluck('rut')->toArray());
function tomarRut(array $digitos, $consumir = true, $desde = 0)
{
    global $pool, $usados;
    $n = count($pool);
    for ($k = 0; $k < $n; $k++) {
        $p = $pool[($desde + $k) % $n];
        $limpio = rutSinFormato($p['rut']);
        if ($consumir && in_array($limpio, $usados, true)) { continue; }
        if (in_array(ultimoDigitoCuerpo($p['rut']), $digitos, true)) {
            if ($consumir) { $usados[] = $limpio; }
            return $p;
        }
    }
    throw new RuntimeException('No quedan RUT disponibles en la lista.');
}

/* --------------------------------------------------------------------------
 | Escenarios: un proveedor por caso
 | nivel: 0 solicitud | 1 lista para invitar | 2 invitación enviada |
 |        3 activado (PM revisa) | 4 revisa Contraloría | 5 revisa Legal
 |        Crediticia | 6 revisa Legal Judicial | 7 revisa Datos Maestros |
 |        8 aprobado | 9 aprobado y enviado a SAP
 * ------------------------------------------------------------------------ */

const AREAS = ['pm', 'contraloria', 'legal_crediticia', 'legal_judicial', 'datos_maestros'];
const NOMBRE_AREA = [
    'pm' => 'PM', 'contraloria' => 'Contraloría', 'legal_crediticia' => 'Legal Crediticia',
    'legal_judicial' => 'Legal Judicial', 'datos_maestros' => 'Datos Maestros',
];

$P = 'Proveedor de productos nacional';
$S = 'Proveedor de servicios';
$I = 'Proveedor de productos internacional';

$escenarios = [
    // Solicitud e invitación
    ['k' => 'solicitud', 'fase' => '1. Solicitud preliminar (sin documentos del solicitante)', 'razon' => 'Distribuidora Los Aromos SpA', 'giro' => 'Distribución de insumos de oficina', 'det' => $P, 'emp' => ['DIMERC'], 'nivel' => 0],
    ['k' => 'listo', 'fase' => '2. Solicitud lista para invitar', 'razon' => 'Servicios Integrales Pacífico Ltda.', 'giro' => 'Mantención de equipos e instalaciones', 'det' => $S, 'emp' => ['DIMERC', 'RHEIN'], 'nivel' => 1],
    ['k' => 'invitado1', 'fase' => '3. Invitación enviada (sin activar)', 'razon' => 'Papelería Industrial del Sur SpA', 'giro' => 'Fabricación de papel y cartón', 'det' => $P, 'emp' => ['OFIMASTER'], 'nivel' => 2],
    ['k' => 'invitado2', 'fase' => '3. Invitación enviada (sin activar)', 'razon' => 'Logística Cordillera Andina SpA', 'giro' => 'Transporte y logística de carga', 'det' => $S, 'emp' => ['DIMERC', 'DIMEIGGS'], 'nivel' => 2],
    // En revisión por etapa
    ['k' => 'pm1', 'fase' => '4. En revisión: PM (documentos pendientes)', 'razon' => 'Comercial Ñuble Hogar SpA', 'giro' => 'Venta al por mayor de artículos de hogar', 'det' => $P, 'emp' => ['DIMERC'], 'nivel' => 3, 'docsPendientes' => true, 'enCurso' => 1.2],
    ['k' => 'pm2', 'fase' => '4. En revisión: PM (etapa vencida)', 'razon' => 'Tecnología y Soporte Austral Ltda.', 'giro' => 'Servicios informáticos', 'det' => $S, 'emp' => ['OFIMASTER', 'PRONOBEL'], 'nivel' => 3, 'enCurso' => 5.4],
    ['k' => 'con1', 'fase' => '5. En revisión: Contraloría', 'razon' => 'Importadora Valle Central SpA', 'giro' => 'Importación de herramientas', 'det' => $P, 'emp' => ['DIMERC', 'PRONOBEL'], 'nivel' => 4, 'enCurso' => 1.6],
    ['k' => 'con2', 'fase' => '5. En revisión: Contraloría (proveedor internacional)', 'razon' => 'Andes Trading Perú S.A.C.', 'giro' => 'Exportación de insumos industriales', 'det' => $I, 'emp' => ['DIMERC'], 'nivel' => 4, 'enCurso' => 0.8, 'internacional' => true],
    ['k' => 'lc1', 'fase' => '6. En revisión: Legal Crediticia (sin evaluación)', 'razon' => 'Constructora Rapa Nui Ltda.', 'giro' => 'Obras menores y remodelaciones', 'det' => $S, 'emp' => ['DIMEIGGS'], 'nivel' => 5, 'enCurso' => 0.7],
    ['k' => 'lc2', 'fase' => '6. En revisión: Legal Crediticia (semáforo amarillo)', 'razon' => 'Ferretería Industrial Maule SpA', 'giro' => 'Venta de ferretería y construcción', 'det' => $P, 'emp' => ['DIMERC'], 'nivel' => 5, 'eval' => 'amarillo', 'pep' => true, 'enCurso' => 2.3],
    ['k' => 'lj1', 'fase' => '7. En revisión: Legal Judicial', 'razon' => 'Textiles del Biobío SpA', 'giro' => 'Confección de uniformes', 'det' => $P, 'emp' => ['RHEIN'], 'nivel' => 6, 'eval' => 'verde', 'enCurso' => 1.1],
    ['k' => 'dm1', 'fase' => '8. En revisión: Datos Maestros', 'razon' => 'Alimentos Valparaíso Gourmet SpA', 'giro' => 'Elaboración de productos alimenticios', 'det' => $P, 'emp' => ['DIMERC', 'OFIMASTER'], 'nivel' => 7, 'eval' => 'verde', 'enCurso' => 0.5],
    // Aprobados y SAP
    ['k' => 'apr1', 'fase' => '9. Aprobado (pendiente de envío a SAP)', 'razon' => 'Embalajes Los Lagos SpA', 'giro' => 'Fabricación de embalajes plásticos', 'det' => $P, 'emp' => ['DIMERC'], 'nivel' => 8, 'eval' => 'verde', 'enCurso' => 0.4],
    ['k' => 'sap1', 'fase' => '10. Aprobado y enviado a SAP (exitoso)', 'razon' => 'Seguridad y Vigilancia Centinela Ltda.', 'giro' => 'Servicios de seguridad privada', 'det' => $S, 'emp' => ['DIMERC', 'RHEIN'], 'nivel' => 9, 'eval' => 'verde', 'sap' => 'exitoso', 'enCurso' => 3.0],
    ['k' => 'sap2', 'fase' => '10. Aprobado y enviado a SAP (exitoso, con re-evaluación)', 'razon' => 'Química Industrial Antofagasta SpA', 'giro' => 'Venta de productos químicos', 'det' => $P, 'emp' => ['PRONOBEL'], 'nivel' => 9, 'eval' => 'verde', 'sap' => 'exitoso', 'reeval' => true, 'enCurso' => 6.0],
    ['k' => 'sap3', 'fase' => '11. Aprobado, SAP observado (datos faltantes)', 'razon' => 'Muebles de Oficina Araucanía Ltda.', 'giro' => 'Fabricación de muebles', 'det' => $P, 'emp' => ['OFIMASTER'], 'nivel' => 9, 'eval' => 'verde', 'sap' => 'observado', 'enCurso' => 1.5],
    // Observados y rechazados
    ['k' => 'obs1', 'fase' => '12. Observado por Contraloría', 'razon' => 'Distribuidora Costa Norte SpA', 'giro' => 'Distribución de artículos de limpieza', 'det' => $P, 'emp' => ['DIMERC'], 'nivel' => 4, 'obs' => 'contraloria', 'comentario' => 'La declaración de relacionados indica un vínculo con un trabajador del holding. Se solicita aclarar la relación y el cargo.', 'enCurso' => 2.0],
    ['k' => 'obs2', 'fase' => '12. Observado por Legal Judicial', 'razon' => 'Transportes Patagonia Austral SpA', 'giro' => 'Transporte de carga terrestre', 'det' => $S, 'emp' => ['DIMEIGGS'], 'nivel' => 6, 'eval' => 'verde', 'obs' => 'legal_judicial', 'comentario' => 'El poder del representante legal no está vigente. Se solicita un certificado de vigencia actualizado.', 'enCurso' => 3.8],
    ['k' => 'rech1', 'fase' => '13. Rechazado por Legal Crediticia (morosidad)', 'razon' => 'Comercializadora Tarapacá Express SpA', 'giro' => 'Comercio al por mayor', 'det' => $P, 'emp' => ['DIMERC'], 'nivel' => 5, 'eval' => 'rojo', 'rech' => 'legal_crediticia', 'comentario' => 'DICOM registra documentos impagos y protestos vigentes. No cumple la política de riesgo crediticio.', 'empresaMorosa' => true, 'enCurso' => 4.0],
    ['k' => 'rech2', 'fase' => '13. Rechazado por Contraloría', 'razon' => 'Asesorías Gestión Global SpA', 'giro' => 'Asesorías de gestión', 'det' => $S, 'emp' => ['DIMERC'], 'nivel' => 4, 'rech' => 'contraloria', 'pep' => true, 'comentario' => 'Declara relación con una persona expuesta políticamente vinculada al área solicitante. No se aprueba por conflicto de interés.', 'enCurso' => 2.7],
    ['k' => 'apr2', 'fase' => '10. Aprobado y enviado a SAP (proveedor de servicios)', 'razon' => 'Limpieza y Mantención Los Robles Ltda.', 'giro' => 'Servicios de aseo industrial', 'det' => $S, 'emp' => ['DIMERC', 'OFIMASTER', 'RHEIN'], 'nivel' => 9, 'eval' => 'verde', 'sap' => 'exitoso', 'enCurso' => 9.0],
];

/* --------------------------------------------------------------------------
 | Escenarios adicionales generados (hasta completar --cantidad)
 | Son deterministas: se repiten igual en cada ejecución, por lo que el script
 | se puede correr de nuevo sin duplicar proveedores.
 * ------------------------------------------------------------------------ */

function generarEscenarios($cuantos, $S, $P, $I)
{
    mt_srand(20261125);
    $empresas = ['DIMERC', 'DIMEIGGS', 'OFIMASTER', 'PRONOBEL', 'RHEIN'];
    $productos = [
        'Comercial' => 'Venta al por mayor de artículos de consumo', 'Distribuidora' => 'Distribución de productos de consumo masivo',
        'Importadora' => 'Importación y venta de productos', 'Ferretería' => 'Venta de ferretería y construcción',
        'Papelería' => 'Fabricación y venta de artículos de papelería', 'Alimentos' => 'Elaboración de productos alimenticios',
        'Textiles' => 'Confección de textiles y uniformes', 'Química' => 'Venta de productos químicos de uso industrial',
        'Embalajes' => 'Fabricación de embalajes y envases', 'Muebles' => 'Fabricación de muebles de oficina',
        'Plásticos' => 'Fabricación de artículos plásticos', 'Maderas' => 'Elaboración y venta de productos de madera',
        'Electro' => 'Venta de artículos electrónicos y eléctricos', 'Bebidas' => 'Distribución de bebidas y snacks',
        'Cosméticos' => 'Distribución de cosméticos y cuidado personal', 'Librería' => 'Venta de libros y artículos escolares',
    ];
    $servicios = [
        'Servicios' => 'Servicios integrales a empresas', 'Logística' => 'Transporte y logística de carga',
        'Transportes' => 'Transporte de carga terrestre', 'Constructora' => 'Obras menores y remodelaciones',
        'Ingeniería' => 'Servicios de ingeniería y proyectos', 'Asesorías' => 'Asesorías de gestión y consultoría',
        'Consultora' => 'Consultoría tecnológica y de procesos', 'Seguridad' => 'Servicios de seguridad privada',
        'Limpieza' => 'Servicios de aseo industrial', 'Tecnología' => 'Servicios informáticos y soporte',
        'Mantención' => 'Mantención de equipos e instalaciones', 'Capacitación' => 'Capacitación y formación para empresas',
        'Publicidad' => 'Servicios de publicidad y marketing', 'Gráfica' => 'Servicios de impresión y gráfica',
    ];
    $marcas = ['Los Andes', 'Del Maipo', 'Santa Rosa', 'Pacífico', 'Cordillera', 'Araucanía', 'Atacama', 'Aconcagua', 'Rapel', 'Cachapoal',
        'Colchagua', 'Elqui', 'Calama', 'Chiloé', 'Patagonia', 'Del Norte', 'Austral', 'Central', 'Sudamericana', 'Vitacura', 'Quilicura',
        'San Antonio', 'Los Boldos', 'Las Palmas', 'El Roble', 'Los Nogales', 'Quillota', 'Curicó', 'Linares', 'Peñalolén', 'Lo Barnechea',
        'La Florida', 'San Bernardo', 'Rancagua Sur', 'Talcahuano', 'Coyhaique', 'Valle Verde', 'Costanera', 'Alto Hospicio', 'Pudahuel'];
    $sufijos = ['SpA', 'Ltda.', 'S.A.', 'SpA', 'E.I.R.L.', 'SpA'];
    $externos = [
        ['Shenzhen Tech Supply Co. Ltd.', 'Importación de componentes electrónicos', 'China', 'Shenzhen'],
        ['Guangzhou Office Goods Co. Ltd.', 'Exportación de artículos de oficina', 'China', 'Guangzhou'],
        ['Lima Packaging S.A.C.', 'Fabricación de envases', 'Perú', 'Lima'],
        ['Industrias Mexicanas del Plástico S.A. de C.V.', 'Fabricación de artículos plásticos', 'México', 'Monterrey'],
        ['Buenos Aires Textil S.A.', 'Exportación de textiles', 'Argentina', 'Buenos Aires'],
        ['Bogotá Cosméticos S.A.S.', 'Exportación de cosméticos', 'Colombia', 'Bogotá'],
        ['Hamburg Industrie Werkzeuge GmbH', 'Exportación de herramientas', 'Alemania', 'Hamburgo'],
        ['São Paulo Alimentos Ltda.', 'Exportación de alimentos envasados', 'Brasil', 'São Paulo'],
    ];
    $comObs = [
        'contraloria' => ['La declaración de relacionados indica un vínculo familiar con una persona del holding. Se solicita aclarar la relación y el cargo.',
            'Falta la información del beneficiario final. Se solicita completar la declaración.'],
        'legal_crediticia' => ['La referencia comercial entregada no tiene fecha ni datos de contacto. Se solicita una referencia vigente.',
            'La fecha de inicio de actividades no coincide con la informada por el SII. Se solicita revisar los datos.'],
        'legal_judicial' => ['El certificado de vigencia del poder tiene más de 90 días. Se solicita uno actualizado.',
            'La escritura no acredita facultades para firmar contratos. Se solicita el poder correspondiente.'],
        'datos_maestros' => ['Los datos bancarios no coinciden con el titular de la cuenta. Se solicita corregirlos antes de crear el proveedor.',
            'Falta el código postal y la comuna de la dirección comercial.'],
    ];
    $comRech = [
        'contraloria' => ['Declara relación con una persona expuesta políticamente vinculada al área solicitante. No se aprueba por conflicto de interés.'],
        'legal_crediticia' => ['DICOM registra documentos impagos y protestos vigentes. No cumple la política de riesgo crediticio.'],
        'legal_judicial' => ['Registra causas judiciales vigentes que afectan al representante legal. No se aprueba.'],
        'datos_maestros' => ['El proveedor ya existe en SAP con otra razón social y los antecedentes no son concordantes. Se rechaza el alta.'],
    ];

    // Plantillas por fase: [cantidad, fase, opciones]
    $plan = [
        [5, '1. Solicitud preliminar (sin documentos del solicitante)', ['nivel' => 0]],
        [6, '2. Solicitud lista para invitar', ['nivel' => 1]],
        [8, '3. Invitación enviada (sin activar)', ['nivel' => 2]],
        [10, '4. En revisión: PM', ['nivel' => 3, 'pm' => true]],
        [10, '5. En revisión: Contraloría', ['nivel' => 4, 'ext' => 2]],
        [8, '6. En revisión: Legal Crediticia', ['nivel' => 5, 'evals' => [null, null, 'amarillo', 'amarillo', 'verde', 'verde', 'verde', 'verde']]],
        [7, '7. En revisión: Legal Judicial', ['nivel' => 6, 'evals' => ['verde', 'verde', 'verde', 'verde', 'verde', 'amarillo', 'amarillo']]],
        [5, '8. En revisión: Datos Maestros', ['nivel' => 7, 'evals' => ['verde']]],
        [5, '9. Aprobado (pendiente de envío a SAP)', ['nivel' => 8, 'evals' => ['verde'], 'largo' => true]],
        [5, '10. Aprobado y enviado a SAP (exitoso)', ['nivel' => 9, 'evals' => ['verde'], 'sap' => 'exitoso', 'largo' => true]],
        [2, '11. Aprobado, SAP observado (datos faltantes)', ['nivel' => 9, 'evals' => ['verde'], 'sap' => 'observado', 'largo' => true]],
        [4, '12. Observado', ['obs' => ['contraloria', 'legal_crediticia', 'legal_judicial', 'datos_maestros']]],
        [4, '13. Rechazado', ['rech' => ['contraloria', 'legal_crediticia', 'legal_judicial', 'datos_maestros']]],
    ];
    $nivelDeArea = ['contraloria' => 4, 'legal_crediticia' => 5, 'legal_judicial' => 6, 'datos_maestros' => 7];

    $usadosNombres = [];
    $out = [];
    $n = 0;
    $nExt = 0;
    foreach ($plan as [$cant, $fase, $op]) {
        for ($j = 0; $j < $cant; $j++) {
            $n++;
            $e = ['k' => sprintf('m%02d', $n), 'fase' => $fase, 'emp' => []];
            $e['nivel'] = $op['nivel'] ?? 0;

            // Nombre, giro y tipo de proveedor
            $externo = isset($op['ext']) && $j < $op['ext'];
            if ($externo) {
                $x = $externos[$nExt++ % count($externos)];
                $e['razon'] = $x[0]; $e['giro'] = $x[1]; $e['det'] = $I; $e['internacional'] = true; $e['pais'] = $x[2]; $e['ciudad'] = $x[3];
            } else {
                $esServicio = mt_rand(0, 99) < 40;
                $lista = $esServicio ? $servicios : $productos;
                $pref = array_keys($lista)[mt_rand(0, count($lista) - 1)];
                do {
                    $razon = $pref . ' ' . $marcas[mt_rand(0, count($marcas) - 1)] . ' ' . $sufijos[mt_rand(0, count($sufijos) - 1)];
                } while (isset($usadosNombres[$razon]));
                $e['razon'] = $razon; $e['giro'] = $lista[$pref]; $e['det'] = $esServicio ? $S : $P;
            }
            $usadosNombres[$e['razon']] = true;
            $cuantasEmp = mt_rand(1, 3);
            $copia = $empresas; shuffle($copia);
            $e['emp'] = array_slice($copia, 0, $cuantasEmp);

            // Variantes por fase
            if (!empty($op['pm'])) {
                if ($j % 3 === 0) { $e['docsPendientes'] = true; }
                $e['enCurso'] = [0.3, 1.1, 4.8, 2.2, 5.9, 0.9, 3.4, 1.7, 6.2, 0.6][$j % 10];
            } elseif (isset($op['evals'])) {
                $ev = $op['evals'][$j % count($op['evals'])];
                if ($ev) { $e['eval'] = $ev; }
                if ($ev === 'amarillo') { $e['pep'] = true; }
            }
            if (isset($op['obs']) || isset($op['rech'])) {
                $area = isset($op['obs']) ? $op['obs'][$j % 4] : $op['rech'][$j % 4];
                $e['nivel'] = $nivelDeArea[$area];
                if (isset($op['obs'])) {
                    $e['obs'] = $area;
                    $e['comentario'] = $comObs[$area][($j + intdiv($j, 4)) % count($comObs[$area])];
                } else {
                    $e['rech'] = $area;
                    $e['comentario'] = $comRech[$area][0];
                    if ($area === 'contraloria') { $e['pep'] = true; }
                }
                if ($e['nivel'] >= 5) { $e['eval'] = 'verde'; }
                if (isset($op['obs']) && $area === 'legal_crediticia') { $e['eval'] = 'amarillo'; }
                if (isset($op['rech']) && $area === 'legal_crediticia') {
                    $e['eval'] = 'rojo';
                    $e['empresaMorosa'] = ($j % 2 === 0);
                }
            }
            if (!empty($op['sap'])) { $e['sap'] = $op['sap']; }
            if (!isset($e['enCurso'])) {
                $e['enCurso'] = !empty($op['largo']) ? mt_rand(10, 250) / 10 : mt_rand(3, 60) / 10;
            }
            if ($j === 0 && ($op['sap'] ?? '') === 'exitoso') { $e['reeval'] = true; }
            $out[] = $e;
        }
    }
    shuffle($out); // se mezclan para que el listado no quede agrupado por fase
    return array_slice($out, 0, max(0, $cuantos));
}

$extra = max(0, $cantidadTotal - count($escenarios));
if ($extra > 0) {
    $escenarios = array_merge($escenarios, generarEscenarios($extra, $S, $P, $I));
}

$lugares = [
    ['Metropolitana de Santiago', 'Santiago'], ['Metropolitana de Santiago', 'Las Condes'], ['Valparaíso', 'Viña del Mar'],
    ['Biobío', 'Concepción'], ['Antofagasta', 'Antofagasta'], ['La Araucanía', 'Temuco'], ['Los Lagos', 'Puerto Montt'],
    ['Coquimbo', 'La Serena'], ['Maule', 'Talca'], ["Libertador General Bernardo O'Higgins", 'Rancagua'],
    ['Tarapacá', 'Iquique'], ['Los Ríos', 'Valdivia'], ['Ñuble', 'Chillán'], ['Metropolitana de Santiago', 'Maipú'],
    ['Atacama', 'Copiapó'], ['Magallanes y de la Antártica Chilena', 'Punta Arenas'], ['Metropolitana de Santiago', 'Providencia'],
    ['Valparaíso', 'Quilpué'], ['Biobío', 'Los Ángeles'], ['Los Lagos', 'Osorno'], ['Metropolitana de Santiago', 'Ñuñoa'],
];
$bancos = ['Banco de Chile', 'BancoEstado', 'Banco Santander', 'BCI', 'Scotiabank', 'Banco Itaú'];
$nombres = ['Camila Rojas', 'Matías Fuentes', 'Javiera Soto', 'Sebastián Araya', 'Francisca Muñoz', 'Diego Contreras', 'Valentina Pizarro',
    'Tomás Espinoza', 'Constanza Vera', 'Nicolás Bravo', 'Fernanda Campos', 'Ignacio Reyes', 'Daniela Cortés', 'Felipe Navarro',
    'Antonia Figueroa', 'Joaquín Tapia', 'Isidora Carrasco', 'Vicente Sepúlveda', 'Catalina Mella', 'Martín Orellana', 'Paula Godoy'];

/* --------------------------------------------------------------------------
 | Limpieza
 * ------------------------------------------------------------------------ */

function limpiarDemo($base)
{
    $usuarios = DB::table('users')->where('email', 'like', '%@' . DOMINIO_DEMO)->pluck('id', 'email')->toArray();
    $ids = DB::table('proveedor_invitations')->where('email', 'like', '%@' . DOMINIO_DEMO)->whereNotNull('proveedor_id')->pluck('proveedor_id')->toArray();
    if ($usuarios) {
        $ids = array_merge($ids, DB::table('proveedores')->whereIn('proveedor_user_id', array_values($usuarios))->pluck('id')->toArray());
    }
    global $escenarios;
    $ids = array_merge($ids, DB::table('proveedores')->whereIn('razon_social', array_column($escenarios, 'razon'))->pluck('id')->toArray());
    $ids = array_values(array_unique($ids));

    foreach ($ids as $pid) {
        foreach (DB::table('proveedor_documentos')->where('proveedor_id', $pid)->pluck('archivo_url') as $url) {
            $ruta = app(App\Services\DocumentoStorageService::class)->resolver($url);
            if ($ruta) { @unlink($ruta); }
        }
    }
    if ($ids) {
        DB::table('proveedor_invitations')->whereIn('proveedor_id', $ids)->delete();
        DB::table('notificaciones')->whereIn('proveedor_id', $ids)->delete();
        DB::table('proveedores')->whereIn('id', $ids)->delete(); // las tablas hijas se borran en cascada
    }
    DB::table('proveedor_invitations')->where('email', 'like', '%@' . DOMINIO_DEMO)->delete();
    if ($usuarios) {
        DB::table('notificaciones')->whereIn('user_id', array_values($usuarios))->delete();
        DB::table('users')->whereIn('id', array_values($usuarios))->delete();
    }
    echo 'Proveedores de demostración eliminados: ' . count($ids) . "\n";
}

if ($limpiar) {
    limpiarDemo($base);
    exit(0);
}

if ($reclave) {
    $cambios = ['password_hash' => password_hash(CLAVE_DEMO, PASSWORD_BCRYPT)];
    if (Illuminate\Support\Facades\Schema::hasColumn('users', 'intentos_fallidos')) {
        $cambios['intentos_fallidos'] = 0;
        $cambios['bloqueado_hasta'] = null;
    }
    $n = DB::table('users')->where('email', 'like', '%@' . DOMINIO_DEMO)->where('role', 'proveedor')->update($cambios);
    echo "Clave actualizada en $n usuarios demo. Nueva clave: " . CLAVE_DEMO . "\n";
    exit(0);
}


/* --------------------------------------------------------------------------
 | Paquete de PDF de ejemplo para subir en vivo durante la demostración
 * ------------------------------------------------------------------------ */
if ($paquete) {
    $destino = $base . '/storage/app/demo_documentos_para_subir';
    if (!is_dir($destino)) { mkdir($destino, 0775, true); }
    $catalogo = DB::table('documento_requisitos')->where('active', 1)->orderBy('sort_order')->orderBy('id')->get();
    $carpetas = [
        'PM' => '00_Solicitante_PM',
        'Proveedor de servicios' => '01_Proveedor_de_servicios',
        'Proveedor de productos nacional' => '02_Proveedor_de_productos_nacional',
        'Proveedor de productos internacional' => '03_Proveedor_de_productos_internacional',
    ];
    $areasNombre = ['pm' => 'PM', 'contraloria' => 'Contraloría', 'legal_crediticia' => 'Legal Crediticia', 'legal_judicial' => 'Legal Judicial', 'datos_maestros' => 'Datos Maestros'];
    $lineas = [];
    $generados = 0;
    foreach ($catalogo as $d) {
        $esFormulario = stripos((string) $d->descripcion, 'formulario digital') === 0;
        if ($esFormulario) { continue; } // las declaraciones se completan y firman dentro de la plataforma
        $destinos = [];
        if ($d->responsable_carga === 'pm') {
            $destinos = ['PM'];
        } elseif ($d->tipo_proveedor_detalle) {
            $destinos = [$d->tipo_proveedor_detalle];
        } else {
            $destinos = ['Proveedor de servicios', 'Proveedor de productos nacional', 'Proveedor de productos internacional'];
        }
        foreach ($destinos as $t) {
            $dir = $destino . '/' . $carpetas[$t];
            if (!is_dir($dir)) { mkdir($dir, 0775, true); }
            $archivo = sprintf('%02d_%s.pdf', (int) $d->sort_order, preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $d->nombre)));
            file_put_contents($dir . '/' . $archivo, renderPdfDemo(htmlDocumentoDemo($d->nombre, 'Empresa de Ejemplo SpA', '76.123.456-7', 'Giro de demostración')));
            $generados++;
            $lineas[] = sprintf("%-40s %-38s %-18s %s/%s", $d->nombre, $t === 'PM' ? 'Solicitante (PM)' : $t, $areasNombre[$d->area_responsable] ?? ($d->area_responsable ?: '-'), $carpetas[$t], $archivo);
        }
    }
    $leeme = "Documentos de ejemplo para subir durante la demostración (datos ficticios)\n\n"
        . "Cada carpeta corresponde a un tipo de proveedor. Los documentos de la carpeta 00 los sube el solicitante (PM) antes de invitar al proveedor; "
        . "los demás los sube el proveedor desde su pantalla de documentos.\n"
        . "La declaración de conflicto de interés y la de relacionados y PEP no se suben: se completan y firman como formulario dentro de la plataforma.\n\n"
        . sprintf("%-40s %-38s %-18s %s\n", 'Documento', 'Quién lo sube / tipo de proveedor', 'Área que valida', 'Archivo')
        . implode("\n", $lineas) . "\n";
    file_put_contents($destino . '/LEEME.txt', $leeme);
    echo $leeme . "\nArchivos generados: $generados\nCarpeta: $destino\n";
    exit(0);
}

/* --------------------------------------------------------------------------
 | Documentos de demostración (PDF generado con Dompdf)
 * ------------------------------------------------------------------------ */

function htmlDocumentoDemo($nombre, $razon, $rut, $giro)
{
    $e = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };
    $fecha = date('d-m-Y');
    $n = mb_strtolower($nombre, 'UTF-8');
    $titulo = $nombre;
    if (strpos($n, 'referencia comercial') !== false) {
        $titulo = 'Referencia comercial';
        $cuerpo = '<p>Santiago, ' . $fecha . '</p>'
            . '<p>A quien corresponda:</p>'
            . '<p>Por la presente certificamos que <strong>' . $e($razon) . '</strong>, RUT ' . $e($rut) . ', dedicada a <em>' . $e(mb_strtolower($giro, 'UTF-8')) . '</em>, '
            . 'mantiene una relación comercial con nuestra empresa desde hace más de tres años.</p>'
            . '<p>Durante ese período ha cumplido con los plazos de entrega y las condiciones pactadas, y no hemos registrado reclamos ni incumplimientos de pago o de calidad.</p>'
            . '<p>Se extiende este certificado a solicitud del interesado, para ser presentado en el proceso de incorporación como proveedor.</p>'
            . '<p style="margin-top:50px">______________________________<br>Gerente de Finanzas<br>Comercial Referente Demo SpA<br>Teléfono +56 2 2555 0100 · referencias@referente-demo.test</p>';
    } elseif (strpos($n, 'poder') !== false) {
        $titulo = 'Certificado de vigencia de poder';
        $cuerpo = '<p>Conservador de Bienes Raíces de Santiago</p>'
            . '<p>Certifico que la sociedad <strong>' . $e($razon) . '</strong>, RUT ' . $e($rut) . ', se encuentra vigente y que su representante legal, '
            . 'señor Gerente General, cuenta con facultades amplias para representarla, celebrar contratos y obligarla ante terceros, según consta en la escritura pública '
            . 'otorgada ante notario y anotada a fojas 12.345, número 8.321, del Registro de Comercio.</p>'
            . '<p>El poder no registra anotaciones de revocación ni limitaciones a la fecha de emisión de este certificado.</p>'
            . '<p>Emitido el ' . $fecha . '.</p><p style="margin-top:40px">______________________________<br>Conservador de Bienes Raíces (documento de demostración)</p>';
    } elseif (strpos($n, 'autorización') !== false || strpos($n, 'correo de aprobación') !== false) {
        $titulo = 'Autorización de jefatura';
        $cuerpo = '<p><strong>De:</strong> gerencia.comercial@holding-demo.test<br><strong>Para:</strong> pm.solicitante@holding-demo.test<br><strong>Fecha:</strong> ' . $fecha . '<br>'
            . '<strong>Asunto:</strong> Aprobación para incorporar a ' . $e($razon) . '</p>'
            . '<p>Estimado:</p><p>Autorizo la incorporación como proveedor de <strong>' . $e($razon) . '</strong> (RUT ' . $e($rut) . '), cuyo giro es ' . $e(mb_strtolower($giro, 'UTF-8')) . '. '
            . 'La compra o contratación está presupuestada y cuenta con el respaldo de esta gerencia.</p><p>Saludos cordiales,<br>Gerencia Comercial</p>';
    } elseif (strpos($n, 'declaración') !== false) {
        $cuerpo = '<p>Declaro, en representación de <strong>' . $e($razon) . '</strong> (RUT ' . $e($rut) . '), que la información entregada es veraz y completa, '
            . 'que no existen relaciones familiares ni de interés con personas del holding que no hayan sido informadas, y que acepto el modelo de prevención de delitos.</p>'
            . '<p>Documento firmado electrónicamente el ' . $fecha . ' (ficticio).</p>';
    } elseif (strpos($n, 'ficha') !== false) {
        $titulo = 'Ficha de incorporación de proveedor';
        $cuerpo = '<table><tr><td>Razón social</td><td>' . $e($razon) . '</td></tr><tr><td>RUT</td><td>' . $e($rut) . '</td></tr><tr><td>Giro</td><td>' . $e($giro) . '</td></tr>'
            . '<tr><td>Condición de pago</td><td>30 días</td></tr><tr><td>Forma de pago</td><td>Transferencia</td></tr><tr><td>Fecha</td><td>' . $fecha . '</td></tr></table>';
    } else {
        $cuerpo = '<p>Documento de respaldo presentado por <strong>' . $e($razon) . '</strong>, RUT ' . $e($rut) . ', para el proceso de incorporación como proveedor.</p>'
            . '<p>Giro: ' . $e($giro) . '. Fecha de emisión: ' . $fecha . '.</p>';
    }
    return '<html><head><meta charset="UTF-8"><style>'
        . 'body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#1b2a33;margin:0}'
        . '.top{background:#0097b8;color:#fff;padding:18px 28px}.top h1{margin:0;font-size:18px}.top small{font-size:10px}'
        . '.c{padding:26px 34px;line-height:1.55}table{border-collapse:collapse;width:100%}td{border-bottom:1px solid #cfd8dc;padding:7px 4px}td:first-child{width:35%;color:#546e7a}'
        . '.pie{position:fixed;bottom:0;left:0;right:0;border-top:1px solid #cfd8dc;padding:8px 34px;font-size:9px;color:#78909c}'
        . '</style></head><body><div class="top"><h1>' . $e($titulo) . '</h1><small>' . $e($razon) . ' · RUT ' . $e($rut) . '</small></div>'
        . '<div class="c">' . $cuerpo . '</div>'
        . '<div class="pie">Documento ficticio generado para la demostración del Portal de Proveedores. No tiene validez legal ni contiene información real.</div>'
        . '</body></html>';
}

function renderPdfDemo($html)
{
    $options = new Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

function pdfDemo($base, $proveedor, $doc)
{
    $html = htmlDocumentoDemo($doc->nombre, $proveedor->razon_social, $proveedor->rut, $proveedor->giro);
    return app(App\Services\PdfDocumentoService::class)->renderizar($html, 'proveedor_documentos', 'demo' . $doc->id, $proveedor->id);
}

function subirDocumento($base, $pid, $docId, $estado, $userId, $comentario = null)
{
    $proveedor = DB::table('proveedores')->where('id', $pid)->first();
    $doc = DB::table('proveedor_documentos')->where('id', $docId)->first();
    $url = $doc->archivo_url ?: pdfDemo($base, $proveedor, $doc);
    DB::table('proveedor_documentos')->where('id', $docId)->update([
        'archivo_nombre' => $doc->archivo_nombre ?: (preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $doc->nombre)) . '.pdf'),
        'archivo_url' => $url,
        'archivo_mime_type' => 'application/pdf',
        'estado' => $estado,
        'uploaded_by_user_id' => $doc->uploaded_by_user_id ?: $userId,
        'comentario' => $comentario ?: $doc->comentario,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function aprobarDocumentosDelArea($base, $pid, $area, $revisorId, array $excluir = [])
{
    $docs = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('area_responsable', $area)->get();
    foreach ($docs as $doc) {
        if (in_array($doc->nombre, $excluir, true)) { continue; }
        if ($doc->estado === 'Pendiente') {
            subirDocumento($base, $pid, $doc->id, 'Subido', $revisorId);
        }
        DB::table('proveedor_documentos')->where('id', $doc->id)->update([
            'estado' => 'Aprobado', 'reviewed_by_user_id' => $revisorId, 'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

/* --------------------------------------------------------------------------
 | Pasos del proceso
 * ------------------------------------------------------------------------ */

function evaluarCredito($base, $pid, array $e, array $ctx)
{
    global $USR;
    $cred = $USR['legal_crediticia'];
    $rutEmpresa = rutParaApi($ctx['rut']);

    // Referencia comercial: aprobada salvo en el caso rojo, donde queda pendiente de revisión
    if (($e['eval'] ?? '') !== 'rojo') {
        $ref = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('nombre', 'Referencia comercial')->first();
        if ($ref) { subirDocumento($base, $pid, $ref->id, 'Aprobado', $cred); }
    }

    // DICOM (simulado): empresa y representante legal
    llamar('DICOM empresa', 'POST', "interno/proveedores/$rutEmpresa/dicom/empresa", $cred);
    llamar('DICOM persona', 'POST', "interno/proveedores/$rutEmpresa/dicom/persona", $cred);

    // Gesintel: se registra una consulta simulada con el color que corresponde al caso
    $colores = ['green', 'green', 'green'];
    if (($e['eval'] ?? '') === 'amarillo') { $colores = ['yellow', 'green', 'green']; }
    $nombreRaz = DB::table('proveedores')->where('id', $pid)->value('razon_social');
    DB::table('proveedor_aml_checks')->insert([
        'proveedor_id' => $pid, 'endpoint' => 'risk', 'rut' => rutSinFormato($ctx['rut']), 'nombre' => $nombreRaz,
        'aml_status' => 'Completado', 'percent' => $colores[0] === 'yellow' ? 35.00 : 5.00,
        'pepsan_color' => $colores[0], 'kycame_color' => $colores[1], 'ubocom_color' => $colores[2],
        'request_json' => json_encode(['simulado' => true]),
        'response_json' => json_encode(['simulado' => true, 'detalle' => 'Consulta registrada por el script de demostración.']),
        'created_by_user_id' => $cred, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $payload = [
        'fecha_inicio_actividades' => date('Y-m-d', strtotime('-' . (3 + ($pid % 9)) . ' years')),
        'dicom_resultado' => 'no_consultado', 'dicom_representante_resultado' => 'no_consultado',
        'observaciones' => 'Evaluación de demostración.',
    ];
    [$s, $r] = api('POST', "interno/proveedores/$pid/evaluacion-crediticia", $cred, $payload);
    exigir($s, $r, 'Evaluación crediticia');
    $evalId = $r['data']['id'];
    llamar('Informe crediticio', 'POST', "interno/proveedores/$pid/evaluacion-crediticia/$evalId/informe", $cred);
    return $r['data']['resultado'];
}

function cerrarArea($base, $pid, $area, $estado, $comentario = null)
{
    global $USR;
    $usuario = $USR[$area === 'pm' ? 'pm' : $area];
    [$s, $r] = api('PUT', "validaciones/proveedores/$pid/areas/$area", $usuario, ['status' => $estado, 'comments' => $comentario]);
    exigir($s, $r, 'Validación ' . $area . ' -> ' . $estado);
}

function procesarEscenario(array $e, $indice)
{
    global $USR, $base, $lugares, $bancos, $nombres, $pool;

    $email = $e['k'] . '@' . DOMINIO_DEMO;
    if (DB::table('proveedor_invitations')->where('email', $email)->exists() || DB::table('users')->where('email', $email)->exists() || DB::table('proveedores')->where('razon_social', $e['razon'])->exists()) {
        return 'ya existía, se omite';
    }

    $internacional = !empty($e['internacional']);
    $ctx = [];

    // RUT de la empresa y del representante según el caso (el simulador de DICOM depende del último dígito)
    $digitosEmpresa = !empty($e['empresaMorosa']) ? [9] : [0, 1, 2, 3, 4, 5, 6];
    $digitosRep = ($e['eval'] ?? '') === 'rojo' && empty($e['empresaMorosa']) ? [7, 8, 9] : [0, 1, 2, 3, 4, 5, 6];
    $pRut = tomarRut($digitosEmpresa);
    $pRep = tomarRut($digitosRep, false, ($indice * 13) % max(1, count($pool)));
    $ctx['rut'] = $pRut['rut'];
    $ctx['listaRut'] = $pRut['lista'];

    $lugar = $internacional ? [$e['ciudad'] ?? 'Lima', $e['ciudad'] ?? 'Lima'] : $lugares[$indice % count($lugares)];
    $contacto = $nombres[$indice % count($nombres)];

    // 0. Solicitud preliminar del PM
    [$s, $r] = api('POST', 'interno/proveedores/solicitud', $USR['pm'], [
        'emailProveedor' => $email, 'empresaIds' => $e['emp'], 'tipo' => $internacional ? 'Internacional' : 'Nacional',
        'tipoProveedorDetalle' => $e['det'], 'razonSocial' => $e['razon'], 'rut' => $pRut['rut'], 'giro' => $e['giro'],
    ]);
    exigir($s, $r, 'Solicitud preliminar');
    $pid = (int) DB::table('proveedores')->where('created_by_user_id', $USR['pm'])->max('id');
    $nivel = $e['nivel'];

    // 1. Documentos del solicitante
    if ($nivel >= 1) {
        llamar('Declaración de conflicto de interés', 'POST', "interno/proveedores/$pid/declaraciones/conflicto-interes", $USR['pm'], [
            'cargo' => 'Product Manager', 'empresa' => 'Dimerc', 'tiene_relacionados' => false, 'acepta' => true,
        ]);
        $aut = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('nombre', 'Autorización de jefatura')->first();
        subirDocumento($base, $pid, $aut->id, 'Subido', $USR['pm']);
    }

    // 2. Invitación
    $token = null;
    if ($nivel >= 2) {
        [$s, $r] = api('POST', 'interno/proveedor-invitations', $USR['pm'], ['email' => $email, 'proveedorId' => (string) $pid]);
        exigir($s, $r, 'Invitación');
        $token = $r['data']['token'];
        $ctx['enlace'] = $r['data']['activationLink'] ?? null;
    }

    // 3. Activación del proveedor (formulario público)
    $proveedorUserId = null;
    if ($nivel >= 3) {
        [$s, $r] = api('POST', 'proveedor/activar', null, [
            'token' => $token, 'password' => CLAVE_DEMO,
            'razonSocial' => $e['razon'], 'rut' => $pRut['rut'], 'tipo' => $internacional ? 'Internacional' : 'Nacional',
            'giro' => $e['giro'], 'proveedorNombre' => $contacto,
            'direccion' => 'Av. Demostración ' . (100 + $indice * 37), 'comuna' => $lugar[1], 'region' => $lugar[0],
            'codigoPostal' => (string) (8000000 + $indice * 1111), 'telefono' => '+56 2 2' . str_pad((string) (3000000 + $indice * 4321), 7, '0', STR_PAD_LEFT),
            'moneda' => $internacional ? 'USD' : 'CLP', 'pais' => $internacional ? ($e['pais'] ?? 'Perú') : 'Chile',
            'representanteLegal' => $nombres[($indice + 5) % count($nombres)], 'representanteCi' => $pRep['rut'],
            'representanteDireccion' => 'Calle Representante ' . (20 + $indice), 'representanteEmail' => 'rep.' . $e['k'] . '@' . DOMINIO_DEMO,
            'representanteCargo' => 'Gerente General',
            'personaContacto' => $contacto, 'personaContactoCargo' => 'Ejecutivo comercial',
            'personaContactoTelefono' => '+56 9 5' . str_pad((string) (1000000 + $indice * 7919), 7, '0', STR_PAD_LEFT),
            'personaContactoEmail' => $email,
            'fechaEscrituraPublica' => date('Y-m-d', strtotime('-' . (4 + $indice % 6) . ' years')), 'notaria' => 'Notaría ' . $nombres[($indice + 9) % count($nombres)],
            'plazoPago' => '30 días', 'formaPago' => 'Transferencia', 'diasDespacho' => '5',
            'bancoPago' => $bancos[$indice % count($bancos)], 'cuentaCorriente' => (string) (10000000 + $indice * 123457),
            'dimensionNegocioTexto' => $e['giro'],
        ]);
        exigir($s, $r, 'Activación del proveedor');
        $proveedorUserId = (int) DB::table('proveedores')->where('id', $pid)->value('proveedor_user_id');

        // El proveedor sube sus documentos (en el caso "pendientes" deja dos sin subir)
        $docsProv = DB::table('proveedor_documentos')->where('proveedor_id', $pid)
            ->where('responsable_carga', 'proveedor')->where('estado', 'Pendiente')
            ->whereNotIn('nombre', ['Declaración Relacionados y PEP'])->orderBy('id')->get();
        $dejarPendientes = !empty($e['docsPendientes']) ? 2 : 0;
        foreach ($docsProv as $i => $doc) {
            if ($i >= count($docsProv) - $dejarPendientes) { break; }
            subirDocumento($base, $pid, $doc->id, 'Subido', $proveedorUserId);
        }

        // Declaración de relacionados y PEP (solo si el catálogo la exige a este tipo de proveedor)
        $tienePep = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('nombre', 'Declaración Relacionados y PEP')->exists();
        if ($tienePep) {
            $payloadPep = [
                'declarante_rut' => $pRep['rut'], 'cargo' => 'Gerente General',
                'es_pep' => !empty($e['pep']), 'acepta_modelo_prevencion' => true, 'acepta' => true,
            ];
            if (!empty($e['pep'])) {
                $payloadPep['peps'] = [['nombre' => 'Persona Ficticia Demo', 'cargo' => 'Concejal', 'relacion' => 'Pariente']];
            }
            llamar('Declaración PEP', 'POST', "proveedor/solicitudes/$pid/declaraciones/relacionados-pep", $proveedorUserId, $payloadPep);
        }
    }

    // 4. Etapas: se aprueban las que corresponden y se trata la etapa actual
    $aprobar = max(0, min(5, $nivel - 3));
    $cerradas = [];
    if ($nivel >= 3) {
        foreach (AREAS as $i => $area) {
            $terminal = ($i === $aprobar) && (!empty($e['obs']) || !empty($e['rech']));
            if ($i > $aprobar) { break; }

            if ($area === 'legal_crediticia' && !empty($e['eval']) && ($i <= $aprobar)) {
                $ctx['semaforo'] = evaluarCredito($base, $pid, $e, $ctx);
            }

            if ($i < $aprobar) {
                aprobarDocumentosDelArea($base, $pid, $area, $USR[$area]);
                cerrarArea($base, $pid, $area, 'Aprobado');
                $cerradas[$area] = 'Aprobado';
                continue;
            }

            if ($terminal) {
                $estado = !empty($e['rech']) ? 'Rechazado' : 'Observado';
                $areaTerminal = !empty($e['rech']) ? $e['rech'] : $e['obs'];
                if ($areaTerminal === $area) {
                    if ($estado === 'Observado') {
                        $doc = DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('area_responsable', $area)
                            ->where('visible_para_internos', 1)->orderBy('id')->first();
                        if ($doc) { subirDocumento($base, $pid, $doc->id, 'Observado', $USR[$area], $e['comentario']); }
                    }
                    cerrarArea($base, $pid, $area, $estado, $e['comentario']);
                    $cerradas[$area] = $estado;
                }
            }
            break;
        }
    }

    // 5. SAP simulado
    if ($nivel >= 9 && !empty($e['sap'])) {
        if ($e['sap'] === 'observado') {
            DB::table('proveedor_detalles')->where('proveedor_id', $pid)->update(['persona_contacto_email' => 'SINCORREO@HOLDING.COM']);
        }
        llamar('Envío a SAP', 'POST', "interno/proveedores/$pid/sap/enviar", $USR['datos_maestros']);
    }
    if (!empty($e['reeval'])) {
        llamar('Re-evaluación', 'POST', "interno/proveedores/$pid/evaluacion-crediticia/reevaluar", $USR['legal_crediticia']);
    }

    // 6. Estado final del proveedor y fechas
    $estadoFinal = 'En revisión';
    if (!empty($e['rech'])) { $estadoFinal = 'Rechazado'; }
    elseif (!empty($e['obs'])) { $estadoFinal = 'Observado'; }
    elseif ($nivel >= 8) { $estadoFinal = 'Aprobado'; }

    if ($nivel >= 8) {
        DB::table('proveedor_documentos')->where('proveedor_id', $pid)->where('visible_para_internos', 1)
            ->update(['estado' => 'Aprobado']);
    }
    DB::table('proveedores')->where('id', $pid)->update(['estado' => $estadoFinal]);

    retrocederFechas($pid, $e, $cerradas);

    return ['pid' => $pid, 'email' => $email, 'rut' => $pRut['rut'], 'lista' => $pRut['lista'], 'enlace' => $ctx['enlace'] ?? null,
        'semaforo' => $ctx['semaforo'] ?? null, 'estado' => $estadoFinal, 'nivel' => $nivel];
}

/**
 * Reparte las fechas hacia atrás para que los plazos, el dashboard y el
 * historial muestren un proceso que ocurrió durante varios días.
 */
function retrocederFechas($pid, array $e, array $cerradas)
{
    $duraciones = ['pm' => 1.4, 'contraloria' => 1.9, 'legal_crediticia' => 2.2, 'legal_judicial' => 1.1, 'datos_maestros' => 0.7];
    $nivel = $e['nivel'] ?? 0;
    $enCurso = $e['enCurso'] ?? 0.5;
    $finUltima = time() - (int) round($enCurso * 86400);

    $suma = 0;
    foreach (AREAS as $area) {
        if (isset($cerradas[$area])) { $suma += $duraciones[$area]; }
    }

    if (count($cerradas) > 0) {
        $inicio = $finUltima - (int) round($suma * 86400);   // la etapa en curso parte donde terminó la última cerrada
    } elseif ($nivel >= 3) {
        $inicio = $finUltima;                                 // PM revisando desde hace $enCurso días
    } else {
        $inicio = time() - (int) round((0.3 + $nivel * 0.4) * 86400);
    }

    $t = $inicio;
    $ahora = date('Y-m-d H:i:s');
    DB::table('proveedores')->where('id', $pid)->update(['created_at' => date('Y-m-d H:i:s', $inicio)]);
    DB::table('proveedor_documentos')->where('proveedor_id', $pid)->update(['created_at' => date('Y-m-d H:i:s', $inicio)]);

    foreach (AREAS as $area) {
        if (!isset($cerradas[$area])) { continue; }
        $t += (int) round($duraciones[$area] * 86400);
        $f = date('Y-m-d H:i:s', $t);
        DB::table('proveedor_workflow_steps')->where('proveedor_id', $pid)->where('area', $area)
            ->update(['created_at' => date('Y-m-d H:i:s', $inicio), 'validated_at' => $f, 'updated_at' => $f]);
        DB::table('proveedor_validaciones')->where('proveedor_id', $pid)->where('area', $area)
            ->update(['created_at' => date('Y-m-d H:i:s', $inicio), 'validated_at' => $f, 'updated_at' => $f]);
    }
    foreach (['proveedor_workflow_steps', 'proveedor_validaciones'] as $tabla) {
        DB::table($tabla)->where('proveedor_id', $pid)->whereNull('validated_at')
            ->update(['created_at' => date('Y-m-d H:i:s', $inicio), 'updated_at' => date('Y-m-d H:i:s', $t)]);
    }
    DB::table('proveedores')->where('id', $pid)->update(['updated_at' => date('Y-m-d H:i:s', $t)]);

    // Historial y notificaciones: se reparten en orden entre el inicio y el último evento
    foreach ([['proveedor_historial', 'proveedor_id'], ['notificaciones', 'proveedor_id']] as [$tabla, $col]) {
        $ids = DB::table($tabla)->where($col, $pid)->orderBy('id')->pluck('id')->toArray();
        $n = count($ids);
        foreach ($ids as $i => $id) {
            $ts = $n > 1 ? $inicio + (int) round(($t - $inicio) * $i / ($n - 1)) : $inicio;
            DB::table($tabla)->where('id', $id)->update(['created_at' => date('Y-m-d H:i:s', $ts)]);
        }
    }
    DB::table('proveedor_invitations')->where('proveedor_id', $pid)->update(['created_at' => date('Y-m-d H:i:s', $inicio + 600)]);
}

/* --------------------------------------------------------------------------
 | Ejecución
 * ------------------------------------------------------------------------ */

echo 'Modo de correo: ' . ($conMailpit ? 'SMTP (Mailpit)' : 'registro en archivo') . "\n\n";
$resumen = [];
$fallos = 0;

foreach ($escenarios as $i => $e) {
    try {
        $res = procesarEscenario($e, $i);
        if (is_string($res)) {
            echo sprintf("OMITIDO  %-9s %s\n", $e['k'], $res);
            continue;
        }
        $res['fase'] = $e['fase'];
        $res['razon'] = $e['razon'];
        $res['k'] = $e['k'];
        $resumen[] = $res;
        echo sprintf("OK       %-9s %s -> %s\n", $e['k'], $e['fase'], $res['estado']);
    } catch (\Throwable $ex) {
        $fallos++;
        echo sprintf("FALLÓ    %-9s %s\n         %s\n", $e['k'], $e['fase'], $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine());
    }
}

file_put_contents($base . '/storage/app/demo_proveedores_resumen.json', json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\n";
echo "Proveedores creados: " . count($resumen) . ", con error: $fallos\n";
echo "Proveedores en la base: " . DB::table('proveedores')->count() . "\n";
echo "\nAcceso de los proveedores (pestaña Proveedor del login):\n";
echo "  Clave de todos: " . CLAVE_DEMO . "\n";
foreach ($resumen as $r) {
    if ($r['nivel'] >= 3) {
        echo sprintf("  %-34s %s\n", $r['k'] . '@' . DOMINIO_DEMO, $r['razon']);
    }
}
foreach ($resumen as $r) {
    if ($r['nivel'] === 2 && $r['enlace']) {
        echo "\nEnlace de activación pendiente de " . $r['k'] . ":\n  " . $r['enlace'] . "\n";
    }
}
echo "\nPara borrar todo lo creado: php tests/manual/seed_demo.php . --limpiar\n";
