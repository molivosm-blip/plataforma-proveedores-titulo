<?php

namespace App\Services;

use App\Models\Proveedores;
use App\Models\ProveedorDicomCheck;

class DicomService
{
    protected $simulado;

    public function __construct(DicomSimuladoService $simulado)
    {
        $this->simulado = $simulado;
    }

    /**
     * real: consulta cobranza.dimerc.cl (solo responde dentro de la red de Dimerc).
     * simulado: usa DicomSimuladoService, con las mismas rutas.
     */
    public function modo(): string
    {
        return strtolower((string) env('DICOM_MODO', 'real')) === 'simulado' ? 'simulado' : 'real';
    }

    public function consultarDicom(
        int $proveedorId,
        string $segmento,
        $user
    ): ProveedorDicomCheck {
        $proveedor = $this->getProveedor($proveedorId);

        /*
         * El segmento "persona" corresponde al representante legal:
         * se consulta su RUT si está registrado en la ficha.
         */
        $rutRepresentante = $segmento === 'persona' && $proveedor->detalle
            ? $this->normalizeRut($proveedor->detalle->representante_ci)
            : '';

        $rut = $rutRepresentante !== '' ? $rutRepresentante : $this->getRutNormalizado($proveedor);

        $path = '/' . rawurlencode($segmento) . '/' . rawurlencode($rut);

        return $this->callAndSave(
            $proveedor,
            $user,
            'dicom',
            $path,
            $segmento,
            $rut,
            null
        );
    }

    public function consultarHistorial(
        int $proveedorId,
        $user
    ): ProveedorDicomCheck {
        $proveedor = $this->getProveedor($proveedorId);
        $rut = $this->getRutNormalizado($proveedor);

        $path = '/historial/' . rawurlencode($rut);

        return $this->callAndSave(
            $proveedor,
            $user,
            'historial',
            $path,
            null,
            $rut,
            null
        );
    }

    public function consultarInformacion(
        int $proveedorId,
        $user
    ): ProveedorDicomCheck {
        $proveedor = $this->getProveedor($proveedorId);
        $rut = $this->getRutNormalizado($proveedor);

        $path = '/informacion/' . rawurlencode($rut);

        return $this->callAndSave(
            $proveedor,
            $user,
            'informacion',
            $path,
            null,
            $rut,
            null
        );
    }

    public function consultarLegado(
        int $proveedorId,
        string $fecha,
        $user
    ): ProveedorDicomCheck {
        $proveedor = $this->getProveedor($proveedorId);
        $rut = $this->getRutNormalizado($proveedor);
        $fecha = $this->validarFecha($fecha);

        $path = '/legado/' . rawurlencode($rut) . '/' . rawurlencode($fecha);

        return $this->callAndSave(
            $proveedor,
            $user,
            'legado',
            $path,
            null,
            $rut,
            $fecha
        );
    }

    public function obtenerChecksProveedor(int $proveedorId)
    {
        return ProveedorDicomCheck::where('proveedor_id', $proveedorId)
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Última consulta DICOM (empresa o persona) registrada para el proveedor.
     */
    public function ultimaConsulta(int $proveedorId, string $segmento): ?ProveedorDicomCheck
    {
        return ProveedorDicomCheck::where('proveedor_id', $proveedorId)
            ->where('endpoint', 'dicom')
            ->where('segmento', $segmento)
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Traduce la respuesta de DICOM a sin_morosidad / con_morosidad.
     *
     * Busca en la respuesta indicadores de morosidad (morosidad, impagos,
     * protestos, deuda). Si encuentra alguno con valor positivo, hay
     * morosidad; si los encuentra todos en cero, no la hay. Si la respuesta
     * no trae ningún indicador reconocible devuelve null y el resultado
     * queda para el criterio del analista.
     */
    public function interpretar(?ProveedorDicomCheck $check): ?string
    {
        if (!$check || $check->http_code < 200 || $check->http_code >= 300) {
            return null;
        }

        $body = json_decode((string) $check->response_json, true);

        if (!is_array($body)) {
            return null;
        }

        $encontrados = 0;
        $positivos = 0;

        array_walk_recursive($body, function ($valor, $clave) use (&$encontrados, &$positivos) {
            if (!is_string($clave) || !preg_match('/moros|impago|protesto|deuda/i', $clave)) {
                return;
            }

            if (is_bool($valor) || is_numeric($valor)) {
                $encontrados++;

                if ((is_bool($valor) && $valor) || (is_numeric($valor) && (float) $valor > 0)) {
                    $positivos++;
                }
            }
        });

        if ($encontrados === 0) {
            return null;
        }

        return $positivos > 0 ? 'con_morosidad' : 'sin_morosidad';
    }

    private function callAndSave(
        Proveedores $proveedor,
        $user,
        string $endpoint,
        string $path,
        ?string $segmento,
        string $rut,
        ?string $fecha
    ): ProveedorDicomCheck {
        if ($this->modo() === 'simulado') {
            $url = 'simulado://dicom' . $path;
            $response = [
                'httpCode' => 200,
                'body'     => $this->simulado->responder($endpoint, $segmento, $rut, $fecha),
            ];
        } else {
            $url = $this->getBaseUrl() . $path;
            $response = $this->curlGetJson($url);
        }

        return ProveedorDicomCheck::create([
            'proveedor_id' => $proveedor->id,
            'endpoint' => $endpoint,
            'segmento' => $segmento,
            'rut' => $rut,
            'fecha_consulta' => $fecha,
            'request_url' => $url,
            'response_json' => json_encode(
                $response['body'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'http_code' => $response['httpCode'],
            'created_by_user_id' => $user ? $user->id : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function curlGetJson(string $url): array
    {
        $ch = curl_init();

        if ($ch === false) {
            throw new \RuntimeException(
                'No fue posible inicializar la conexión con el servicio DICOM.'
            );
        }

        $timeout = max(1, (int) env('DICOM_TIMEOUT', 30));

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($rawResponse === false) {
            throw new \RuntimeException(
                'No fue posible consultar el servicio DICOM: ' . $curlError
            );
        }

        $decoded = json_decode($rawResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = [
                'raw' => $rawResponse,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(
                'El servicio DICOM respondió con código HTTP ' . $httpCode . '.'
            );
        }

        return [
            'httpCode' => $httpCode,
            'body' => $decoded,
        ];
    }

    private function getBaseUrl(): string
    {
        $baseUrl = rtrim(
            trim((string) env('DICOM_BASE_URL', '')),
            '/'
        );

        if ($baseUrl === '') {
            throw new \RuntimeException(
                'DICOM_BASE_URL no está configurado.'
            );
        }

        $parts = parse_url($baseUrl);

        if (
            !is_array($parts) ||
            empty($parts['scheme']) ||
            empty($parts['host']) ||
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new \RuntimeException(
                'DICOM_BASE_URL no contiene una URL HTTP válida.'
            );
        }

        return $baseUrl;
    }

    private function getProveedor(int $proveedorId): Proveedores
    {
        return Proveedores::with([
            'usuarioProveedor',
            'detalle',
            'sociedadesHabilitadas',
        ])->findOrFail($proveedorId);
    }

    private function getRutNormalizado(Proveedores $proveedor): string
    {
        $rut = $this->normalizeRut($proveedor->rut);

        if ($rut === '') {
            throw new \RuntimeException(
                'El proveedor no tiene un RUT válido para consultar DICOM.'
            );
        }

        return $rut;
    }

    private function normalizeRut(?string $rut): string
    {
        $value = trim((string) $rut);
        $value = preg_replace('/[^0-9Kk]/', '', $value);

        return strtoupper((string) $value);
    }

    private function validarFecha(string $fecha): string
    {
        $fecha = trim($fecha);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            $date === false ||
            ($errors !== false && (
                $errors['warning_count'] > 0 ||
                $errors['error_count'] > 0
            )) ||
            $date->format('Y-m-d') !== $fecha
        ) {
            throw new \InvalidArgumentException(
                'La fecha de consulta DICOM debe utilizar el formato YYYY-MM-DD.'
            );
        }

        return $fecha;
    }
}
