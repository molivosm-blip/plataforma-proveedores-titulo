<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmpresasController extends Controller
{
    /**
     * Listar empresas.
     *
     * GET /v1/empresas
     */
    public function index(Request $request)
    {
        try {
            $empresas = DB::table('empresas')
                ->select('id', 'nombre')
                ->orderBy('nombre', 'asc')
                ->get()
                ->map(function ($empresa) {
                    return [
                        'id' => (string) $empresa->id,
                        'nombre' => $empresa->nombre,
                    ];
                });

            return $this->responseData([
                'data' => $empresas,
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener documentos requeridos por empresa.
     *
     * GET /v1/empresas/{id}/documentos-requeridos
     */
    public function documentosRequeridos(Request $request, $id)
    {
        try {
            $empresaId = $this->resolveEmpresaId((string) $id);

            if (!$empresaId) {
                return $this->responseData([
                    'error' => true,
                    'message' => 'Empresa no encontrada',
                ], 404);
            }

            return $this->responseData([
                'empresaId' => $empresaId,
                'documentos' => $this->getDocumentosRequeridos($empresaId),
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Catálogos base para proveedores/documentos.
     *
     * GET /v1/catalogos/proveedores
     */
    public function catalogosProveedores(Request $request)
    {
        try {
            return $this->responseData([
                'tiposProveedor' => [
                    'Empresa Relacionada',
                    'Banco',
                    'Proveedores Esporádicos',
                    'Proveedores Extranjeros',
                    'Proveedores Nacionales',
                    'Empresa de Transporte',
                    'Acreedores Nacionales',
                    'Importaciones',
                    'Proveedor de Servicios',
                    'Proveedor de Productos Nacional',
                    'Proveedor de Productos Internacional',
                ],
                'objetivosCreacion' => [
                    'Creación por compra de productos esporádica (SIN acuerdo comercial)',
                    'Creación por compra de productos mensual (CON acuerdo comercial)',
                    'Creación por contratación de servicios',
                ],
                'tiposSapBasicos' => [
                    'Nacional',
                    'Internacional',
                ],
                'monedas' => [
                    'CLP',
                    'USD',
                    'EUR',
                ],
                'paises' => [
                    'Chile',
                    'Perú',
                    'Argentina',
                    'Brasil',
                    'Colombia',
                    'México',
                    'Estados Unidos',
                    'China',
                    'España',
                ],
                'formasPago' => [
                    'Transferencia',
                    'Cheque',
                    'Vale Vista',
                ],
                'estadosProveedor' => [
                    'En revisión',
                    'Observado',
                    'Aprobado',
                    'Rechazado',
                ],
                'estadosDocumento' => [
                    'Pendiente',
                    'Subido',
                    'Observado',
                    'Aprobado',
                ],
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Catálogo de grupos de compra.
     *
     * GET /v1/catalogos/grupos-compra
     */
    public function gruposCompra(Request $request)
    {
        try {
            return $this->responseData([
                'data' => $this->getGruposCompra(),
            ], 200);
        } catch (\Exception $e) {
            return $this->responseData([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resuelve el ID real de empresa contra la tabla empresas.
     */
    private function resolveEmpresaId(string $empresaId): ?string
    {
        $raw = trim($empresaId);

        if ($raw === '') {
            return null;
        }

        $candidates = array_values(array_unique([
            $raw,
            strtoupper($raw),
            strtolower($raw),
        ]));

        $empresa = DB::table('empresas')
            ->whereIn('id', $candidates)
            ->first();

        return $empresa ? (string) $empresa->id : null;
    }

    /**
     * Documentos base requeridos por empresa.
     */
    private function getDocumentosRequeridos(string $empresaId): array
    {
        return [
            'Correo de aprobación de Gerencia',
            'Declaración Conflicto de Interés Solicitante',
            'Referencia comercial',
        ];
    }

    /**
     * Catálogo local de grupos de compra.
     */
    private function getGruposCompra(): array
    {
        return [
            ['codigo' => 'C00', 'denominacion' => 'Corp Chile NOP'],
            ['codigo' => 'C01', 'denominacion' => 'Corp Perú NOP'],
            ['codigo' => 'C02', 'denominacion' => 'Intercompany NOP'],
            ['codigo' => 'D00', 'denominacion' => 'Dimerc Chile NOP'],
            ['codigo' => 'D01', 'denominacion' => 'Oficina y Librería'],
            ['codigo' => 'D02', 'denominacion' => 'Aseo y Limpieza'],
            ['codigo' => 'D03', 'denominacion' => 'Aseo Tissue'],
            ['codigo' => 'D04', 'denominacion' => 'Alimentos'],
            ['codigo' => 'D05', 'denominacion' => 'Tecnología'],
            ['codigo' => 'D06', 'denominacion' => 'Cuid.Pers y Salud'],
            ['codigo' => 'D07', 'denominacion' => 'Ferretería'],
            ['codigo' => 'D08', 'denominacion' => 'Juguetería'],
            ['codigo' => 'D09', 'denominacion' => 'Cotillón'],
            ['codigo' => 'D10', 'denominacion' => 'Hogar'],
            ['codigo' => 'D11', 'denominacion' => 'Textil y Accesori.'],
            ['codigo' => 'H00', 'denominacion' => 'Holding NOP'],
            ['codigo' => 'H01', 'denominacion' => 'Compras Holding'],
            ['codigo' => 'M00', 'denominacion' => 'Dimeiggs NOP'],
            ['codigo' => 'M01', 'denominacion' => 'Oficina y Librería'],
            ['codigo' => 'M02', 'denominacion' => 'Papel Tissue'],
            ['codigo' => 'M03', 'denominacion' => 'Aseo'],
            ['codigo' => 'M04', 'denominacion' => 'Alimentos'],
            ['codigo' => 'M05', 'denominacion' => 'Tecnología'],
            ['codigo' => 'M06', 'denominacion' => 'Cuidado Personal'],
            ['codigo' => 'M07', 'denominacion' => 'Ferretería'],
            ['codigo' => 'M08', 'denominacion' => 'Juguetería'],
            ['codigo' => 'M09', 'denominacion' => 'Cotillón'],
            ['codigo' => 'M10', 'denominacion' => 'Hogar'],
            ['codigo' => 'M11', 'denominacion' => 'Textil y Accesori.'],
            ['codigo' => 'O00', 'denominacion' => 'Ofimarket NOP'],
            ['codigo' => 'O01', 'denominacion' => 'Compras Ofimarket'],
            ['codigo' => 'P00', 'denominacion' => 'Pronobel NOP'],
            ['codigo' => 'P01', 'denominacion' => 'Oficina y Librería'],
            ['codigo' => 'P02', 'denominacion' => 'Papel Tissue'],
            ['codigo' => 'P03', 'denominacion' => 'Aseo'],
            ['codigo' => 'P04', 'denominacion' => 'Alimentos'],
            ['codigo' => 'P05', 'denominacion' => 'Tecnología'],
            ['codigo' => 'P06', 'denominacion' => 'Cuidado Personal'],
            ['codigo' => 'P07', 'denominacion' => 'Ferretería'],
            ['codigo' => 'P08', 'denominacion' => 'Juguetería'],
            ['codigo' => 'P09', 'denominacion' => 'Cotillón'],
            ['codigo' => 'P10', 'denominacion' => 'Hogar'],
            ['codigo' => 'P11', 'denominacion' => 'Textil y Accesori.'],
            ['codigo' => 'R00', 'denominacion' => 'Dimerc Perú NOP'],
            ['codigo' => 'R01', 'denominacion' => 'Oficina y Librería'],
            ['codigo' => 'R02', 'denominacion' => 'Aseo y Limpieza'],
            ['codigo' => 'R03', 'denominacion' => 'Aseo Tissue'],
            ['codigo' => 'R04', 'denominacion' => 'Alimentos'],
            ['codigo' => 'R05', 'denominacion' => 'Tecnología'],
            ['codigo' => 'R06', 'denominacion' => 'Cuidado Personal'],
            ['codigo' => 'R07', 'denominacion' => 'Ferretería'],
            ['codigo' => 'R08', 'denominacion' => 'Juguetería'],
            ['codigo' => 'R09', 'denominacion' => 'Cotillón'],
            ['codigo' => 'R10', 'denominacion' => 'Hogar'],
            ['codigo' => 'R11', 'denominacion' => 'Textil y Accesori.'],
            ['codigo' => 'S00', 'denominacion' => 'Servicios NOP'],
            ['codigo' => 'S01', 'denominacion' => 'Compras Servicios'],
            ['codigo' => 'T00', 'denominacion' => 'Translogic NOP'],
            ['codigo' => 'T01', 'denominacion' => 'Compras Translogic'],
            ['codigo' => 'U00', 'denominacion' => 'Pacífico NOP'],
            ['codigo' => 'U01', 'denominacion' => 'Compras Pacífico'],
        ];
    }
}
