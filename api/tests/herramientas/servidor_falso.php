<?php
/*
 * Servidor que imita a un servicio externo (DICOM, Gesintel) con distintos
 * fallos, para probar cómo responde el portal cuando el tercero no anda bien.
 * Se ejecuta con: php -S 127.0.0.1:PUERTO tests/herramientas/servidor_falso.php
 *
 *   /ok/...        200 con JSON válido
 *   /error500/...  500
 *   /lento/...     espera 4 segundos y recién responde
 *   /basura/...    200 pero con texto que no es JSON
 *   /noautorizado  401
 */
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$modo = explode('/', ltrim($ruta, '/'))[0] ?? '';

header('Content-Type: application/json');

switch ($modo) {
    case 'error500':
        http_response_code(500);
        echo json_encode(['message' => 'fallo interno del tercero']);
        break;
    case 'lento':
        sleep(4);
        echo json_encode(['ok' => true]);
        break;
    case 'basura':
        echo '<html>esto no es json</html>';
        break;
    case 'noautorizado':
        http_response_code(401);
        echo json_encode(['message' => 'token invalido']);
        break;
    default:
        echo json_encode(['ok' => true, 'resultado' => []]);
}
