<?php

namespace App\Services;

use App\Models\Proveedores;
use App\Models\ProveedorAmlCheck;
use App\Models\ProveedorAmlRecord;

class AmlUpdateService
{
    /*
    |--------------------------------------------------------------------------
    | Métodos públicos - Consultas AMLUpdate
    |--------------------------------------------------------------------------
    */

    public function consultarRiesgoProveedor(
        int $proveedorId,
        $user
    ): ProveedorAmlCheck {
        $proveedor = $this->getProveedor($proveedorId);

        $rut = $this->normalizeRut(
            $proveedor->rut
        );

        $nombre = $this->getProveedorNombre(
            $proveedor
        );

        $params = $this->buildSearchParams(
            $rut,
            $nombre
        );

        $response = $this->callAmlGet(
            '/mvc/rest/getAMLRisk',
            $params
        );

        return $this->guardarCheck(
            $proveedor,
            $user,
            'getAMLRisk',
            $params,
            $response
        );
    }

    public function consultarDetalleProveedor(
        int $proveedorId,
        $user
    ): ProveedorAmlCheck {
        $proveedor = $this->getProveedor($proveedorId);

        $rut = $this->normalizeRut(
            $proveedor->rut
        );

        $nombre = $this->getProveedorNombre(
            $proveedor
        );

        $params = $this->buildSearchParams(
            $rut,
            $nombre
        );

        $response = $this->callAmlGet(
            '/mvc/rest/getAMLResult',
            $params
        );

        return $this->guardarCheck(
            $proveedor,
            $user,
            'getAMLResult',
            $params,
            $response
        );
    }

    public function consultarFlagsProveedor(
        int $proveedorId,
        $user
    ): ProveedorAmlCheck {
        $proveedor = $this->getProveedor($proveedorId);

        $rut = $this->normalizeRut(
            $proveedor->rut
        );

        $nombre = $this->getProveedorNombre(
            $proveedor
        );

        $params = $this->buildSearchParams(
            $rut,
            $nombre
        );

        $response = $this->callAmlGet(
            '/mvc/rest/getFlagResults',
            $params
        );

        return $this->guardarCheck(
            $proveedor,
            $user,
            'getFlagResults',
            $params,
            $response
        );
    }

    public function generarReporteProveedor(
        int $proveedorId,
        $user
    ): ProveedorAmlCheck {
        $proveedor = $this->getProveedor($proveedorId);

        $rut = $this->normalizeRut(
            $proveedor->rut
        );

        $nombre = $this->getProveedorNombre(
            $proveedor
        );

        $params = $this->buildSearchParams(
            $rut,
            $nombre
        );

        $response = $this->callAmlGet(
            '/mvc/rest/getAMLReport',
            $params
        );

        return $this->guardarCheck(
            $proveedor,
            $user,
            'getAMLReport',
            $params,
            $response
        );
    }

    /**
     * Descarga el último reporte AML generado para un proveedor.
     *
     * El enlace entregado por Gesintel requiere el token directo en:
     *
     * Authorization: TOKEN
     */
    public function descargarReporteProveedor(
        int $proveedorId
    ): array {
        $proveedor = $this->getProveedor(
            $proveedorId
        );

        $ultimoReporte = ProveedorAmlCheck::where(
            'proveedor_id',
            $proveedor->id
        )
            ->where(
                'endpoint',
                'getAMLReport'
            )
            ->whereNotNull('pdf_link')
            ->where('pdf_link', '<>', '')
            ->orderBy('id', 'desc')
            ->first();

        if (!$ultimoReporte) {
            throw new \Exception(
                'No existe un reporte AML generado para este proveedor'
            );
        }

        $pdfUrl = trim(
            (string) $ultimoReporte->pdf_link
        );

        if ($pdfUrl === '') {
            throw new \Exception(
                'El reporte AML no contiene una URL válida'
            );
        }

        $this->validatePdfUrl($pdfUrl);

        $file = $this->downloadProtectedFile(
            $pdfUrl
        );

        $rut = $this->normalizeRut(
            $proveedor->rut
        );

        $file['fileName'] =
            'reporte-aml-' .
            ($rut !== '' ? $rut : $proveedor->id) .
            '.pdf';

        return $file;
    }

    /*
    |--------------------------------------------------------------------------
    | Métodos públicos - Registro AMLUpdate / UBOFinder
    |--------------------------------------------------------------------------
    */

    public function registrarProveedor(
        int $proveedorId,
        $user
    ): ProveedorAmlRecord {
        $proveedor = $this->getProveedor(
            $proveedorId
        );

        $detalle = $proveedor->detalle;

        $rut = $this->normalizeRut(
            $proveedor->rut
        );

        $nombre = $this->getProveedorNombre(
            $proveedor
        );

        $record = [
            'rut' => $rut,
            'nombre' => $nombre,
            'email' => $this->getProveedorEmail(
                $proveedor
            ),
            'category' => 'PROVEEDOR',
            'status' => 'ACTIVE',
            'grupo' => 'PROVEEDORES',
            'processUF' =>
                $proveedor->tipo === 'Nacional',
            'nombreRep' => $detalle
                ? $detalle->representante_legal
                : null,
            'type' => 'Entity',
            'citizenship' => null,
            'dateBirth' => null,
            'phone' => $detalle
                ? $detalle->telefono
                : null,
            'telefono' => $detalle
                ? $detalle->telefono
                : null,
            'contactName' => $detalle
                ? $detalle->persona_contacto
                : null,
            'position' => $detalle
                ? $detalle->persona_contacto_cargo
                : null,
            'activity' => $proveedor->giro,
            'purpose' =>
                'Relación comercial proveedor',
            'alias' => null,
            'area' => 'Proveedores',
            'residentOf' => $detalle
                ? $detalle->pais
                : null,
            'address' => $detalle
                ? $detalle->direccion
                : null,
            'paisDir' => $detalle
                ? $detalle->pais
                : null,
            'region' => $detalle
                ? $detalle->region
                : null,
            'city' => $detalle
                ? $detalle->comuna
                : null,
            'extId' => (string) $proveedor->id,
            'relacionados' =>
                $this->buildRelacionados(
                    $proveedor
                ),
        ];

        $body = [
            'record' => $record,
            'sendForm' => false,
        ];

        $response = $this->callAmlPost(
            '/rest/record/saveRecord',
            $body
        );

        return $this->guardarRecord(
            $proveedor,
            $user,
            $body,
            $response,
            'saveRecord'
        );
    }

    public function consultarRegistroProveedor(
        int $proveedorId,
        $user
    ): ProveedorAmlRecord {
        $proveedor = $this->getProveedor(
            $proveedorId
        );

        $rut = $this->normalizeRut(
            $proveedor->rut
        );

        $body = [
            'rut' => $rut,
        ];

        $response = $this->callAmlPost(
            '/rest/record/getRecord',
            $body
        );

        return $this->guardarRecord(
            $proveedor,
            $user,
            $body,
            $response,
            'getRecord'
        );
    }

    public function obtenerPdfRegistroProveedor(
        int $proveedorId,
        $user
    ): ProveedorAmlRecord {
        $proveedor = $this->getProveedor(
            $proveedorId
        );

        $rut = $this->normalizeRut(
            $proveedor->rut
        );

        $body = [
            'rut' => $rut,
        ];

        $response = $this->callAmlPost(
            '/rest/record/getPdfRecord',
            $body
        );

        return $this->guardarRecord(
            $proveedor,
            $user,
            $body,
            $response,
            'getPdfRecord'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Consultas locales
    |--------------------------------------------------------------------------
    */

    public function obtenerChecksProveedor(
        int $proveedorId
    ) {
        return ProveedorAmlCheck::where(
            'proveedor_id',
            $proveedorId
        )
            ->orderBy('id', 'desc')
            ->get();
    }

    public function obtenerRecordsProveedor(
        int $proveedorId
    ) {
        return ProveedorAmlRecord::where(
            'proveedor_id',
            $proveedorId
        )
            ->orderBy('id', 'desc')
            ->get();
    }

    public function obtenerUltimoCheckProveedor(
        int $proveedorId
    ): ?ProveedorAmlCheck {
        return ProveedorAmlCheck::where(
            'proveedor_id',
            $proveedorId
        )
            ->orderBy('id', 'desc')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Persistencia
    |--------------------------------------------------------------------------
    */

    private function guardarCheck(
        Proveedores $proveedor,
        $user,
        string $endpoint,
        array $request,
        array $response
    ): ProveedorAmlCheck {
        $firstResult =
            $this->extractFirstResult(
                $response
            );

        $compliance =
            isset($firstResult['compliance']) &&
            is_array(
                $firstResult['compliance']
            )
                ? $firstResult['compliance']
                : [];

        $pepsan =
            isset($compliance['PEPSAN']) &&
            is_array($compliance['PEPSAN'])
                ? $compliance['PEPSAN']
                : [];

        $kycame =
            isset($compliance['KYCAME']) &&
            is_array($compliance['KYCAME'])
                ? $compliance['KYCAME']
                : [];

        $ubocom =
            isset($compliance['UBOCOM']) &&
            is_array($compliance['UBOCOM'])
                ? $compliance['UBOCOM']
                : [];

        $pdfLink =
            $this->extractPdfLink(
                $response
            );

        return ProveedorAmlCheck::create([
            'proveedor_id' =>
                $proveedor->id,

            'endpoint' =>
                $endpoint,

            'rut' =>
                $this->normalizeRut(
                    $proveedor->rut
                ),

            'nombre' =>
                $this->getProveedorNombre(
                    $proveedor
                ),

            'aml_status' =>
                $this->extractAmlStatus(
                    $firstResult,
                    $response
                ),

            'percent' =>
                $firstResult['percent'] ??
                $response['percent'] ??
                null,

            'pepsan_color' =>
                $pepsan['color'] ??
                null,

            'kycame_color' =>
                $kycame['color'] ??
                null,

            'ubocom_color' =>
                $ubocom['color'] ??
                null,

            'pdf_link' =>
                $pdfLink,

            'request_json' =>
                json_encode(
                    $request,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            'response_json' =>
                json_encode(
                    $response,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            'created_by_user_id' =>
                $user
                    ? $user->id
                    : null,

            'created_at' =>
                date('Y-m-d H:i:s'),

            'updated_at' =>
                date('Y-m-d H:i:s'),
        ]);
    }

    private function guardarRecord(
        Proveedores $proveedor,
        $user,
        array $request,
        array $response,
        string $operation
    ): ProveedorAmlRecord {
        $record =
            $request['record'] ?? [];

        return ProveedorAmlRecord::create([
            'proveedor_id' =>
                $proveedor->id,

            'rut' =>
                $this->normalizeRut(
                    $proveedor->rut
                ),

            'nombre' =>
                $this->getProveedorNombre(
                    $proveedor
                ),

            'category' =>
                $record['category'] ??
                'PROVEEDOR',

            'status' =>
                $record['status'] ??
                null,

            'ext_id' =>
                $record['extId'] ??
                (string) $proveedor->id,

            'request_json' =>
                json_encode(
                    [
                        'operation' => $operation,
                        'payload' => $request,
                    ],
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            'response_json' =>
                json_encode(
                    $response,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            'created_by_user_id' =>
                $user
                    ? $user->id
                    : null,

            'created_at' =>
                date('Y-m-d H:i:s'),

            'updated_at' =>
                date('Y-m-d H:i:s'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP externo - AMLUpdate
    |--------------------------------------------------------------------------
    */

    private function callAmlGet(
        string $path,
        array $params = []
    ): array {
        $baseUrl =
            $this->getAmlUpdateBaseUrl();

        $token =
            $this->getAmlUpdateToken();

        $url =
            $baseUrl .
            '/' .
            ltrim($path, '/');

        if (!empty($params)) {
            $url .=
                '?' .
                http_build_query(
                    $params,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
        }

        return $this->curlJson(
            'GET',
            $url,
            null,
            $token
        );
    }

    private function callAmlPost(
        string $path,
        array $body
    ): array {
        $baseUrl =
            $this->getAmlUpdateBaseUrl();

        $token =
            $this->getAmlUpdateToken();

        $url =
            $baseUrl .
            '/' .
            ltrim($path, '/');

        return $this->curlJson(
            'POST',
            $url,
            $body,
            $token
        );
    }

    /**
     * Descarga el PDF protegido generado por Gesintel.
     *
     * El token se envía directamente:
     *
     * Authorization: TOKEN
     *
     * No se utiliza Bearer.
     */
    private function downloadProtectedFile(
        string $url
    ): array {
        $token =
            $this->getAmlUpdateToken();

        $ch = curl_init();

        if ($ch === false) {
            throw new \Exception(
                'No fue posible inicializar cURL para descargar el reporte AML'
            );
        }

        $headers = [
            'Accept: application/pdf',
            'Authorization: ' . $token,
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL =>
                $url,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_HTTPGET =>
                true,

            CURLOPT_HTTPHEADER =>
                $headers,

            CURLOPT_CONNECTTIMEOUT =>
                10,

            CURLOPT_TIMEOUT =>
                (int) env(
                    'AMLUPDATE_TIMEOUT',
                    60
                ),

            /*
             * Se deshabilitan redirecciones para evitar
             * enviar el token a otro dominio.
             */
            CURLOPT_FOLLOWLOCATION =>
                false,

            CURLOPT_MAXREDIRS =>
                0,

            CURLOPT_SSL_VERIFYPEER =>
                true,

            CURLOPT_SSL_VERIFYHOST =>
                2,

            CURLOPT_HEADER =>
                false,
        ]);

        $content =
            curl_exec($ch);

        $curlError =
            curl_error($ch);

        $httpCode =
            (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

        $contentType =
            (string) curl_getinfo(
                $ch,
                CURLINFO_CONTENT_TYPE
            );

        curl_close($ch);

        if ($content === false) {
            throw new \Exception(
                'Error descargando el reporte AML: ' .
                $curlError
            );
        }

        if (
            $httpCode < 200 ||
            $httpCode >= 300
        ) {
            throw new \Exception(
                'AMLUpdate rechazó la descarga del reporte. HTTP ' .
                $httpCode
            );
        }

        $isPdfContentType =
            stripos(
                $contentType,
                'application/pdf'
            ) !== false;

        /*
         * Se evita str_starts_with para mantener
         * compatibilidad con versiones anteriores de PHP.
         */
        $hasPdfSignature =
            substr($content, 0, 5) ===
            '%PDF-';

        if (
            !$isPdfContentType &&
            !$hasPdfSignature
        ) {
            throw new \Exception(
                'AMLUpdate no devolvió un archivo PDF válido'
            );
        }

        return [
            'content' =>
                $content,

            'contentType' =>
                'application/pdf',

            'fileName' =>
                'reporte-aml.pdf',
        ];
    }

    private function curlJson(
        string $method,
        string $url,
        ?array $body = null,
        ?string $authorization = null
    ): array {
        $ch = curl_init();

        if ($ch === false) {
            throw new \Exception(
                'No fue posible inicializar cURL para AMLUpdate'
            );
        }

        $method = strtoupper(
            trim($method)
        );

        $headers = [
            'Accept: application/json',
        ];

        if ($method !== 'GET') {
            $headers[] =
                'Content-Type: application/json';
        }

        /*
         * Token directo sin Bearer.
         */
        if (
            $authorization !== null &&
            trim($authorization) !== ''
        ) {
            $headers[] =
                'Authorization: ' .
                trim($authorization);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL =>
                $url,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CUSTOMREQUEST =>
                $method,

            CURLOPT_HTTPHEADER =>
                $headers,

            CURLOPT_CONNECTTIMEOUT =>
                10,

            CURLOPT_TIMEOUT =>
                max(
                    1,
                    (int) env(
                        'AMLUPDATE_TIMEOUT',
                        60
                    )
                ),

            CURLOPT_FOLLOWLOCATION =>
                false,

            CURLOPT_MAXREDIRS =>
                0,

            CURLOPT_SSL_VERIFYPEER =>
                true,

            CURLOPT_SSL_VERIFYHOST =>
                2,
        ]);

        if ($body !== null) {
            $encodedBody = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            if ($encodedBody === false) {
                curl_close($ch);

                throw new \Exception(
                    'No fue posible serializar la solicitud AMLUpdate'
                );
            }

            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                $encodedBody
            );
        }

        $rawResponse =
            curl_exec($ch);

        $curlError =
            curl_error($ch);

        $httpCode =
            (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

        curl_close($ch);

        if ($rawResponse === false) {
            error_log('AMLUpdate no respondió: ' . $curlError);

            throw new \App\Exceptions\ServicioExternoNoDisponibleException('Gesintel (AML)', $curlError);
        }

        $decoded =
            json_decode(
                $rawResponse,
                true
            );

        if ($httpCode >= 500) {
            error_log('AMLUpdate respondió HTTP ' . $httpCode);

            throw new \App\Exceptions\ServicioExternoNoDisponibleException('Gesintel (AML)', 'HTTP ' . $httpCode);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'AMLUpdate devolvió una respuesta JSON no válida.'
            );
        }

        $decoded['_httpCode'] =
            $httpCode;

        if (
            $httpCode < 200 ||
            $httpCode >= 300
        ) {
            $message = isset($decoded['message'])
                ? trim((string) $decoded['message'])
                : '';

            throw new \RuntimeException(
                $message !== ''
                    ? 'AMLUpdate respondió HTTP ' . $httpCode . ': ' . $message
                    : 'AMLUpdate respondió con código HTTP ' . $httpCode . '.'
            );
        }

        return $decoded;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de URL y ambiente
    |--------------------------------------------------------------------------
    */

    private function getAmlUpdateBaseUrl(): string
    {
        $environment = strtolower(
            trim(
                (string) env(
                    'AMLUPDATE_ENV',
                    'qa'
                )
            )
        );

        if (
            $environment === 'production' ||
            $environment === 'prod'
        ) {
            $baseUrl = env(
                'AMLUPDATE_BASE_URL',
                'https://www.amlupdate.com'
            );
        } else {
            $baseUrl = env(
                'AMLUPDATE_QA_BASE_URL',
                'https://qa.amlupdate.com'
            );
        }

        $baseUrl = trim(
            (string) $baseUrl
        );

        if ($baseUrl === '') {
            throw new \Exception(
                'No está configurada la URL base de AMLUpdate'
            );
        }

        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);

        if (
            !is_array($parts) ||
            empty($parts['scheme']) ||
            empty($parts['host']) ||
            strtolower((string) $parts['scheme']) !== 'https'
        ) {
            throw new \Exception(
                'La URL base de AMLUpdate debe ser una URL HTTPS válida'
            );
        }

        return $baseUrl;
    }

    private function getAmlUpdateToken(): string
    {
        $token = trim(
            (string) env(
                'AMLUPDATE_TOKEN',
                ''
            )
        );

        if ($token === '') {
            throw new \Exception(
                'No está configurado AMLUPDATE_TOKEN en .env'
            );
        }

        return $token;
    }

    /**
     * Valida que la URL del PDF corresponda a Gesintel.
     *
     * Evita que el backend descargue URLs arbitrarias.
     */
    private function validatePdfUrl(
        string $url
    ): void {
        $parsedUrl =
            parse_url($url);

        if (
            !is_array($parsedUrl) ||
            empty($parsedUrl['scheme']) ||
            empty($parsedUrl['host'])
        ) {
            throw new \Exception(
                'La URL del reporte AML no es válida'
            );
        }

        $scheme = strtolower(
            (string) $parsedUrl['scheme']
        );

        $host = strtolower(
            (string) $parsedUrl['host']
        );

        $path = (string) (
            $parsedUrl['path'] ?? ''
        );

        $allowedHosts = array_values(array_unique(array_filter([
            strtolower((string) parse_url(
                (string) env('AMLUPDATE_QA_BASE_URL', ''),
                PHP_URL_HOST
            )),
            strtolower((string) parse_url(
                (string) env('AMLUPDATE_BASE_URL', ''),
                PHP_URL_HOST
            )),
            strtolower((string) parse_url(
                $this->getAmlUpdateBaseUrl(),
                PHP_URL_HOST
            )),
        ])));

        if ($scheme !== 'https') {
            throw new \Exception(
                'La URL del reporte AML debe utilizar HTTPS'
            );
        }

        if (
            !in_array(
                $host,
                $allowedHosts,
                true
            )
        ) {
            throw new \Exception(
                'El dominio del reporte AML no está autorizado'
            );
        }

        if (
            strpos(
                $path,
                '/mvc/getPDFBusquedaUnica/'
            ) !== 0
        ) {
            throw new \Exception(
                'La ruta del reporte AML no está autorizada'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers del proveedor
    |--------------------------------------------------------------------------
    */

    private function getProveedor(
        int $proveedorId
    ): Proveedores {
        return Proveedores::with([
            'usuarioProveedor',
            'detalle',
            'sociedadesHabilitadas',
        ])->findOrFail($proveedorId);
    }

    private function getProveedorNombre(
        Proveedores $proveedor
    ): string {
        return trim(
            $proveedor->razon_social
                ?: 'Proveedor sin razón social'
        );
    }

    private function getProveedorEmail(
        Proveedores $proveedor
    ): ?string {
        if ($proveedor->usuarioProveedor) {
            return $proveedor
                ->usuarioProveedor
                ->email;
        }

        if (
            $proveedor->detalle &&
            $proveedor
                ->detalle
                ->persona_contacto_email
        ) {
            return $proveedor
                ->detalle
                ->persona_contacto_email;
        }

        return null;
    }

    private function normalizeRut(
        ?string $rut
    ): string {
        $value = trim((string) $rut);
        $value = preg_replace('/[^0-9Kk]/', '', $value);

        return strtoupper((string) $value);
    }

    private function buildSearchParams(
        string $rut,
        string $nombre
    ): array {
        if ($rut === '') {
            throw new \InvalidArgumentException(
                'El proveedor no tiene un RUT válido para consultar AMLUpdate.'
            );
        }

        return [
            'rut' => $rut,
        ];
    }

    private function buildRelacionados(
        Proveedores $proveedor
    ): array {
        $detalle =
            $proveedor->detalle;

        if (!$detalle) {
            return [];
        }

        $relacionados = [];

        if ($detalle->representante_legal) {
            $relacionados[] = [
                'dni' =>
                    $this->normalizeRut(
                        $detalle->representante_ci
                    ),

                'name' =>
                    $detalle->representante_legal,

                'tipoDoc' =>
                    'RUT',

                'email' =>
                    $detalle->representante_email,

                'type' =>
                    $detalle->representante_cargo
                        ?: 'Representante Legal',

                'residentOf' =>
                    $detalle->pais,

                'citizenship' =>
                    null,

                'dateBirth' =>
                    null,
            ];
        }

        if ($detalle->persona_contacto) {
            $relacionados[] = [
                'dni' =>
                    null,

                'name' =>
                    $detalle->persona_contacto,

                'tipoDoc' =>
                    'OTRO',

                'email' =>
                    $detalle->persona_contacto_email,

                'type' =>
                    $detalle->persona_contacto_cargo
                        ?: 'Contacto',

                'residentOf' =>
                    $detalle->pais,

                'citizenship' =>
                    null,

                'dateBirth' =>
                    null,
            ];
        }

        return $relacionados;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de respuesta AML
    |--------------------------------------------------------------------------
    */

    private function extractFirstResult(
        array $response
    ): array {
        if (
            isset($response['results']) &&
            is_array($response['results']) &&
            isset($response['results'][0]) &&
            is_array($response['results'][0])
        ) {
            return $response['results'][0];
        }

        if (
            isset($response['results']) &&
            is_array($response['results']) &&
            isset(
                $response['results']['amlStatus']
            )
        ) {
            return $response['results'];
        }

        return [];
    }

    private function extractAmlStatus(
        array $firstResult,
        array $response
    ): ?string {
        if (
            isset(
                $firstResult['amlStatus']
            )
        ) {
            return $firstResult['amlStatus'];
        }

        if (
            isset($response['amlStatus'])
        ) {
            return $response['amlStatus'];
        }

        $compliance =
            isset($firstResult['compliance']) &&
            is_array(
                $firstResult['compliance']
            )
                ? $firstResult['compliance']
                : [];

        $colors = [];

        foreach (
            [
                'PEPSAN',
                'KYCAME',
                'UBOCOM',
            ] as $base
        ) {
            if (
                isset($compliance[$base]) &&
                is_array(
                    $compliance[$base]
                ) &&
                isset(
                    $compliance[$base]['color']
                )
            ) {
                $colors[] = strtoupper(
                    (string) $compliance[$base]['color']
                );
            }
        }

        if (
            in_array(
                'RED',
                $colors,
                true
            )
        ) {
            return 'RED';
        }

        if (
            in_array(
                'YELLOW',
                $colors,
                true
            )
        ) {
            return 'YELLOW';
        }

        if (
            in_array(
                'ORANGE',
                $colors,
                true
            )
        ) {
            return 'ORANGE';
        }

        if (
            !empty($colors) &&
            count(
                array_unique($colors)
            ) === 1 &&
            $colors[0] === 'GREEN'
        ) {
            return 'GREEN';
        }

        if (
            isset($response['status']) &&
            $response['status'] === 'OK' &&
            isset($response['size']) &&
            (int) $response['size'] === 0
        ) {
            return 'SIN_COINCIDENCIAS';
        }

        return $response['status']
            ?? null;
    }

    private function extractPdfLink(
        array $response
    ): ?string {
        if (
            isset($response['pdfLink']) &&
            is_string(
                $response['pdfLink']
            )
        ) {
            return $response['pdfLink'];
        }

        if (
            isset($response['results']) &&
            is_array($response['results'])
        ) {
            if (
                isset(
                    $response['results']['pdfLink']
                ) &&
                is_string(
                    $response['results']['pdfLink']
                )
            ) {
                return $response['results']['pdfLink'];
            }

            foreach (
                $response['results']
                as $item
            ) {
                if (
                    is_array($item) &&
                    isset($item['pdfLink']) &&
                    is_string(
                        $item['pdfLink']
                    )
                ) {
                    return $item['pdfLink'];
                }
            }
        }

        if (
            isset($response['data']) &&
            is_array($response['data']) &&
            isset(
                $response['data']['pdfLink']
            ) &&
            is_string(
                $response['data']['pdfLink']
            )
        ) {
            return $response['data']['pdfLink'];
        }

        return null;
    }
}