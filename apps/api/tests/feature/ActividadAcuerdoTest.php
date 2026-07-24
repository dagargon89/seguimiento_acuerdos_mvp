<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FakeTokenVerifier;
use Tests\Support\FechaFijaTrait;

/**
 * GET /acuerdos/{id}/actividad — bitácora unificada (quick win #3).
 * Une avances + auditoría de ciclo de vida (crear/editar/corresponsables)
 * sin duplicar concluir/reabrir (ya presentes como avance).
 *
 * @group database
 *
 * @internal
 */
final class ActividadAcuerdoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use FechaFijaTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private const UID = [
        'direccion@demo.test'              => 'fb-demo-direccion-001',
        'coordinacion.operativa@demo.test' => 'fb-demo-coord-001',
        'responsable.uno@demo.test'        => 'fb-demo-resp-001',
    ];

    private FakeTokenVerifier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarFechaTest();
        $this->fake = new FakeTokenVerifier();
        Services::injectMock('tokenVerifier', $this->fake);
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        Services::reset();
        $this->resetFechaTest();
        parent::tearDown();
    }

    private function como(string $email): self
    {
        $this->fake->exito(self::UID[$email], $email, true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->withBodyFormat('json');

        return $this;
    }

    private function cuerpo(TestResponse $r): array
    {
        return json_decode($r->response()->getJSON(), true);
    }

    public function testIncluyeCreacionYAvancesOrdenadosDesc(): void
    {
        // Acuerdo 3 (abierto, del seed) ya trae 1 avance sembrado (el seed NO
        // audita 'crear' — InitialSeeder trunca `auditoria` y no inserta filas
        // para los acuerdos sembrados; el evento crear real se cubre en
        // testEventoCrearApareceParaAcuerdoCreadoViaLote). Registramos un
        // avance más reciente para confirmar el orden desc por created_at.
        $this->como('direccion@demo.test')
            ->post('api/v1/acuerdos/3/avances', ['tipo' => 'avance', 'descripcion' => 'Avance de prueba']);

        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos/3/actividad');
        $r->assertStatus(200);
        $eventos = $this->cuerpo($r)['data'];

        // Orden desc por created_at (el avance recién creado es el más nuevo → primero).
        $this->assertSame('avance', $eventos[0]['tipo']);
        $this->assertSame('avance:', substr($eventos[0]['id'], 0, 7));

        // Cada evento trae fuente y descripción no vacía.
        foreach ($eventos as $ev) {
            $this->assertContains($ev['fuente'], ['avance', 'auditoria']);
            $this->assertNotSame('', $ev['descripcion']);
        }
    }

    public function testConclusionApareceUnaSolaVezSinDuplicar(): void
    {
        // Concluir genera avance 'validacion' + auditoría 'concluir'. La bitácora
        // debe mostrar SOLO el avance validacion (no el registro de auditoría concluir).
        $this->como('direccion@demo.test')
            ->patch('api/v1/acuerdos/3/concluir', ['nota' => 'Cerrado en reunión']);

        $r        = $this->como('direccion@demo.test')->get('api/v1/acuerdos/3/actividad');
        $eventos  = $this->cuerpo($r)['data'];
        $tipos    = array_column($eventos, 'tipo');

        $this->assertSame(1, count(array_filter($tipos, static fn ($t) => $t === 'validacion')));
        $this->assertNotContains('concluir', $tipos);
        $this->assertNotContains('reabrir', $tipos);
    }

    public function testEdicionDescribeCamposCambiados(): void
    {
        $this->como('direccion@demo.test')
            ->patch('api/v1/acuerdos/3', ['tema' => 'Tema actualizado']);

        $r       = $this->como('direccion@demo.test')->get('api/v1/acuerdos/3/actividad');
        $eventos = $this->cuerpo($r)['data'];
        $editar  = array_values(array_filter($eventos, static fn ($e) => $e['tipo'] === 'editar'));

        $this->assertNotEmpty($editar);
        $this->assertStringContainsString('tema', $editar[0]['descripcion']);
    }

    public function testEventoCrearApareceParaAcuerdoCreadoViaLote(): void
    {
        // El seed no audita 'crear' (InitialSeeder trunca `auditoria`), así que
        // el único camino real para un evento 'crear' es POST /acuerdos/lote
        // (AcuerdosController::insertarAcuerdo audita 'crear' por cada renglón).
        $lote = [
            'reunion'  => ['nombre' => 'Reunión de prueba actividad', 'fecha' => '2026-07-09'],
            'acuerdos' => [[
                'tema' => 'Tema de prueba actividad', 'accion' => 'Acción de prueba actividad',
                'responsable_id' => 4, 'corresponsables_ids' => [], 'area_id' => 1,
                'fecha_compromiso' => '2026-07-10', 'enlace' => null, 'observaciones' => null,
                'recordatorio_dias' => null,
            ]],
        ];

        $creado = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);
        $creado->assertStatus(201);
        $nuevoId = $this->cuerpo($creado)['data'][0]['id'];

        $r       = $this->como('direccion@demo.test')->get("api/v1/acuerdos/{$nuevoId}/actividad");
        $r->assertStatus(200);
        $eventos = $this->cuerpo($r)['data'];
        $crear   = array_values(array_filter($eventos, static fn ($e) => $e['tipo'] === 'crear'));

        $this->assertNotEmpty($crear);
        $this->assertSame('auditoria', $crear[0]['fuente']);
        $this->assertSame('Creó el acuerdo', $crear[0]['descripcion']);
    }

    public function testPendienteRecibe403(): void
    {
        // Un UID sin usuario operativo se autorregistra como 'pendiente' (ADR-006).
        $this->fake->exito('fb-uid-nuevo-999', 'nuevo@planjuarez.org', true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->withBodyFormat('json');
        $this->post('api/v1/registro', ['nombre' => 'Nuevo Usuario']);

        $r = $this->get('api/v1/acuerdos/3/actividad');
        $r->assertStatus(403);
        $this->assertSame('cuenta_pendiente', $this->cuerpo($r)['error']);
    }
}
