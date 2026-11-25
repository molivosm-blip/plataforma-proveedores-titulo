<?php

namespace Tests;

use App\Models\ProveedorDeclaracion;
use App\Models\User;
use App\Services\AreaPermissionService;
use App\Services\DeclaracionService;
use App\Services\EvaluacionCrediticiaService;

/*
| Pruebas de la lógica que no depende de la base de datos:
| semáforo crediticio, validación de declaraciones, integridad de la
| firma y permisos por área.
*/
class ProcesoProveedorTest extends TestCase
{
    private function regla(string $estado): array
    {
        return ['clave' => 'x', 'nombre' => 'x', 'estado' => $estado, 'detalle' => ''];
    }

    public function test_semaforo_verde_cuando_todas_las_reglas_cumplen(): void
    {
        $service = app(EvaluacionCrediticiaService::class);

        $this->assertSame('verde', $service->resultado([
            $this->regla(EvaluacionCrediticiaService::CUMPLE),
            $this->regla(EvaluacionCrediticiaService::CUMPLE),
        ]));
    }

    public function test_semaforo_amarillo_con_una_advertencia(): void
    {
        $service = app(EvaluacionCrediticiaService::class);

        $this->assertSame('amarillo', $service->resultado([
            $this->regla(EvaluacionCrediticiaService::CUMPLE),
            $this->regla(EvaluacionCrediticiaService::ADVERTENCIA),
        ]));
    }

    public function test_semaforo_rojo_si_alguna_regla_no_cumple(): void
    {
        $service = app(EvaluacionCrediticiaService::class);

        $this->assertSame('rojo', $service->resultado([
            $this->regla(EvaluacionCrediticiaService::ADVERTENCIA),
            $this->regla(EvaluacionCrediticiaService::NO_CUMPLE),
        ]));
    }

    public function test_declaracion_sin_aceptacion_es_rechazada(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(DeclaracionService::class)->normalizarRespuestas(ProveedorDeclaracion::TIPO_CONFLICTO, [
            'tiene_relacionados' => false,
        ]);
    }

    public function test_declaracion_con_relacionados_exige_al_menos_una_fila(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(DeclaracionService::class)->normalizarRespuestas(ProveedorDeclaracion::TIPO_CONFLICTO, [
            'tiene_relacionados' => true,
            'relacionados'       => [['nombre' => '', 'relacion' => 'Primo']],
            'acepta'             => true,
        ]);
    }

    public function test_declaracion_pep_exige_detalle_si_declara_pep(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(DeclaracionService::class)->normalizarRespuestas(ProveedorDeclaracion::TIPO_PEP, [
            'declarante_rut'           => '11.111.111-1',
            'es_pep'                   => true,
            'peps'                     => [],
            'acepta_modelo_prevencion' => true,
            'acepta'                   => true,
        ]);
    }

    public function test_declaracion_pep_valida_descarta_filas_vacias(): void
    {
        $respuestas = app(DeclaracionService::class)->normalizarRespuestas(ProveedorDeclaracion::TIPO_PEP, [
            'declarante_rut'           => '11.111.111-1',
            'cargo'                    => 'Gerente General',
            'es_pep'                   => true,
            'peps'                     => [
                ['nombre' => 'Persona Ficticia', 'cargo' => 'Alcalde', 'relacion' => 'Tío'],
                ['nombre' => '', 'cargo' => '', 'relacion' => ''],
            ],
            'acepta_modelo_prevencion' => true,
            'acepta'                   => true,
        ]);

        $this->assertTrue($respuestas['es_pep']);
        $this->assertCount(1, $respuestas['peps']);
        $this->assertSame([], $respuestas['trabajadores_relacionados']);
    }

    public function test_hash_de_firma_detecta_respuestas_modificadas(): void
    {
        $service = app(DeclaracionService::class);
        $declarante = ['nombre' => 'Solicitante', 'email' => 'pm@example.test', 'rut' => null, 'cargo' => 'PM'];

        $original = $service->calcularHash(1, 'conflicto_interes', ['tiene_relacionados' => false], $declarante, '2026-11-03 10:00:00', 7);
        $repetido = $service->calcularHash(1, 'conflicto_interes', ['tiene_relacionados' => false], $declarante, '2026-11-03 10:00:00', 7);
        $alterado = $service->calcularHash(1, 'conflicto_interes', ['tiene_relacionados' => true], $declarante, '2026-11-03 10:00:00', 7);

        $this->assertSame($original, $repetido);
        $this->assertNotSame($original, $alterado);
        $this->assertSame(64, strlen($original));
    }

    public function test_cada_area_actua_solo_por_su_area(): void
    {
        $permisos = new AreaPermissionService();

        $contraloria = new User(['role' => 'usuario_area', 'area' => 'contraloria']);
        $admin = new User(['role' => 'admin_portal']);
        $pm = new User(['role' => 'pm_user']);

        $this->assertTrue($permisos->actsForArea($contraloria, 'contraloria'));
        $this->assertFalse($permisos->actsForArea($contraloria, 'legal_crediticia'));
        $this->assertFalse($permisos->actsForArea($contraloria, 'datos_maestros'));
        $this->assertTrue($permisos->actsForArea($admin, 'datos_maestros'));
        $this->assertTrue($permisos->actsForArea($pm, 'pm'));
        $this->assertFalse($permisos->actsForArea($pm, 'legal_crediticia'));
    }
}
