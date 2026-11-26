<?php
/*
 * Prueba de carga contra la API en marcha (no usa la base de pruebas: lee
 * datos reales, solo con consultas GET, así que no modifica nada).
 *
 * Simula varios usuarios internos conectados a la vez y mide cuánto
 * demora cada respuesta y cuántas fallan. Por cada nivel de usuarios
 * simultáneos reporta: solicitudes por segundo, tiempos (mediana, p95 y
 * máximo) y errores.
 *
 * Uso, desde la carpeta api-proveedores (con la API encendida):
 *   php tests/manual/carga.php .                       (niveles 1, 10, 25 y 50)
 *   php tests/manual/carga.php . http://localhost:8000 1,5,10 60
 *       (carpeta, dirección de la API, niveles, solicitudes por nivel)
 *
 * Nota: la API de la demostración corre con el servidor de desarrollo de
 * PHP (php -S), que atiende de a una solicitud. En producción se usa
 * PHP-FPM con nginx, que atiende varias en paralelo, por lo que los
 * tiempos reales serían mejores que estos.
 */
$base = $argv[1] ?? '.';
$api = rtrim($argv[2] ?? 'http://localhost:8000', '/');
$niveles = array_map('intval', explode(',', $argv[3] ?? '1,10,25,50'));
$porNivel = max(10, (int) ($argv[4] ?? 100));

$app = require $base . '/bootstrap/app.php';

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;

if (getenv('CARGA_USER_ID')) {
    // Para probar sin leer la base: se indica el id del usuario con CARGA_USER_ID.
    $usuario = (object) ['id' => (int) getenv('CARGA_USER_ID'), 'email' => 'usuario ' . getenv('CARGA_USER_ID'), 'role' => 'indicado'];
} else {
    $usuario = DB::table('users')->where('role', 'admin_portal')->where('active', 1)->first()
        ?? DB::table('users')->where('active', 1)->whereIn('role', ['pm_admin', 'usuario_area', 'admin_area'])->first();
}

if (!$usuario) {
    fwrite(STDERR, "No encontré un usuario interno activo para la prueba.\n");
    exit(1);
}

$token = JWT::encode(['sub' => $usuario->id, 'iat' => time(), 'exp' => time() + 1800], env('JWT_SECRET'), 'HS256');

$escenarios = [
    ['Panel (dashboard)', '/v1/dashboard', true],
    ['Listado de proveedores', '/v1/interno/proveedores', true],
    ['Notificaciones', '/v1/notificaciones', true],
    ['Exportación CSV', '/v1/interno/proveedores/exportar', true],
    ['Catálogo público', '/v1/catalogos/proveedores', false],
];

function percentil(array $ordenados, float $p): float
{
    if (!$ordenados) {
        return 0.0;
    }
    $i = (int) ceil($p / 100 * count($ordenados)) - 1;

    return $ordenados[max(0, min($i, count($ordenados) - 1))];
}

function nuevoCurl(string $url, ?string $token)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', $token ? 'Authorization: Bearer ' . $token : null]),
    ]);

    return $ch;
}

// Comprobación previa: la API debe estar encendida.
$sonda = nuevoCurl($api . '/v1/catalogos/proveedores', null);
curl_exec($sonda);
if (curl_getinfo($sonda, CURLINFO_HTTP_CODE) !== 200) {
    fwrite(STDERR, "La API no responde en $api. Enciéndela primero (iniciar_demo.bat).\n");
    exit(1);
}
curl_close($sonda);

echo "Prueba de carga - " . date('d-m-Y H:i:s') . "\n";
echo "API: $api | usuario de prueba: {$usuario->email} ({$usuario->role})\n";
echo "Solicitudes por nivel: $porNivel, repartidas entre " . count($escenarios) . " consultas distintas\n\n";
printf("%-12s %-10s %-10s %-10s %-10s %-10s %-8s %-8s\n", 'Simultáneos', 'Solicitudes', 'Por seg.', 'Mediana', 'p95', 'Máximo', 'Errores', 'Total(s)');

$hayErrores = false;
$resumenEscenarios = [];

foreach ($niveles as $nivel) {
    $mh = curl_multi_init();
    $enVuelo = [];
    $tiempos = [];
    $errores = 0;
    $lanzadas = 0;
    $inicio = microtime(true);

    $lanzar = function () use (&$lanzadas, &$enVuelo, $mh, $api, $escenarios, $token, $porNivel) {
        if ($lanzadas >= $porNivel) {
            return false;
        }
        $esc = $escenarios[$lanzadas % count($escenarios)];
        $ch = nuevoCurl($api . $esc[1], $esc[2] ? $token : null);
        curl_multi_add_handle($mh, $ch);
        $enVuelo[(int) $ch] = [$ch, $esc[0]];
        $lanzadas++;

        return true;
    };

    for ($i = 0; $i < $nivel; $i++) {
        $lanzar();
    }

    do {
        curl_multi_exec($mh, $activos);
        curl_multi_select($mh, 0.05);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            [, $nombre] = $enVuelo[(int) $ch];
            $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $seg = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $tiempos[] = $seg;
            $resumenEscenarios[$nombre][$nivel][] = $seg;

            if ($info['result'] !== CURLE_OK || $codigo < 200 || $codigo >= 300) {
                $errores++;
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($enVuelo[(int) $ch]);
            $lanzar();
        }
    } while ($activos || $enVuelo);

    curl_multi_close($mh);
    $total = microtime(true) - $inicio;
    sort($tiempos);

    printf(
        "%-12d %-10d %-10.1f %-10s %-10s %-10s %-8d %-8.1f\n",
        $nivel,
        count($tiempos),
        count($tiempos) / max($total, 0.001),
        number_format(percentil($tiempos, 50) * 1000, 0) . ' ms',
        number_format(percentil($tiempos, 95) * 1000, 0) . ' ms',
        number_format(end($tiempos) * 1000, 0) . ' ms',
        $errores,
        $total
    );

    if ($errores > 0) {
        $hayErrores = true;
    }
}

echo "\nMediana por consulta y nivel de usuarios simultáneos:\n";
foreach ($resumenEscenarios as $nombre => $porNivelTiempos) {
    $fila = [];
    foreach ($porNivelTiempos as $nivel => $ts) {
        sort($ts);
        $fila[] = "$nivel usuarios: " . number_format(percentil($ts, 50) * 1000, 0) . ' ms';
    }
    echo sprintf("  %-26s %s\n", $nombre, implode(' | ', $fila));
}

echo "\nResultado: " . ($hayErrores ? "HUBO ERRORES (revisar arriba)" : "sin errores en ninguna solicitud") . "\n";
exit($hayErrores ? 2 : 0);
