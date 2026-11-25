<?php

namespace App\Services;

/*
|--------------------------------------------------------------------------
| Servicio DICOM simulado
|--------------------------------------------------------------------------
|
| La API de DICOM (cobranza.dimerc.cl) solo responde dentro de la red de
| Dimerc. Con DICOM_MODO=simulado el portal usa estas respuestas, con las
| mismas rutas que la API real, para demostrar el flujo fuera de la red.
| Dentro de la red se usa DICOM_MODO=real y no cambia nada más.
|
| La respuesta depende del último dígito del RUT (sin dígito verificador),
| para que la demostración sea repetible:
| - 0 a 6: sin morosidad
| - 7 u 8: con documentos impagos
| - 9:     con documentos impagos y protestos
|
*/
class DicomSimuladoService
{
    public function responder(string $endpoint, ?string $segmento, string $rut, ?string $fecha): array
    {
        $cuerpo = substr($rut, 0, -1);
        $ultimo = (int) substr($cuerpo, -1);

        $impagos = $ultimo >= 7 ? ($ultimo - 5) : 0;
        $protestos = $ultimo === 9 ? 1 : 0;
        $monto = $impagos * 485000;

        $resumen = [
            'tiene_morosidad'            => $impagos > 0,
            'cantidad_documentos_impagos' => $impagos,
            'monto_total_impago'         => $monto,
            'cantidad_protestos'         => $protestos,
            'fecha_ultima_morosidad'     => $impagos > 0 ? date('Y-m-d', strtotime('-45 days')) : null,
        ];

        $base = [
            'fuente'         => 'SIMULADO',
            'rut'            => $rut,
            'segmento'       => $segmento,
            'fecha_consulta' => $fecha ?: date('Y-m-d'),
        ];

        if ($endpoint === 'historial') {
            return $base + [
                'historial' => [
                    ['periodo' => date('Y-m', strtotime('-2 months')), 'documentos_impagos' => $impagos],
                    ['periodo' => date('Y-m', strtotime('-1 months')), 'documentos_impagos' => $impagos],
                    ['periodo' => date('Y-m'), 'documentos_impagos' => $impagos],
                ],
            ];
        }

        if ($endpoint === 'informacion') {
            return $base + [
                'informacion' => [
                    'nombre'        => $segmento === 'persona' ? 'Persona de demostración' : 'Empresa de demostración',
                    'actividad'     => 'Actividad registrada (simulada)',
                    'estado'        => 'Vigente',
                ],
                'resumen' => $resumen,
            ];
        }

        return $base + [
            'resumen' => $resumen,
            'detalle' => $impagos > 0
                ? array_map(function ($n) {
                    return [
                        'tipo_documento' => 'Factura',
                        'monto'          => 485000,
                        'fecha_vencimiento' => date('Y-m-d', strtotime('-' . (45 + $n * 10) . ' days')),
                        'acreedor'       => 'Acreedor de demostración ' . $n,
                    ];
                }, range(1, $impagos))
                : [],
        ];
    }
}
