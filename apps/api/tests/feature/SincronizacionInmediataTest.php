<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FakeCalendarSync;
use Tests\Support\FakeTokenVerifier;

/**
 * Sincronización inmediata con Google Calendar (ADR-009): cada escritura de
 * acuerdo dispara `CalendarSync::sincronizar()` justo después del commit —
 * el evento aparece al momento, sin esperar la corrida diaria (que sigue
 * existiendo como red de reintentos). Casos SI-01..SI-04.
 *
 * @group database
 *
 * @internal
 */
final class SincronizacionInmediataTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private FakeTokenVerifier $fake;
    private FakeCalendarSync $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeTokenVerifier();
        Services::injectMock('tokenVerifier', $this->fake);
        $this->calendar = new FakeCalendarSync();
        Services::injectMock('calendarSync', $this->calendar);
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function como(string $email, string $uid): self
    {
        $this->fake->exito($uid, $email, true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->withBodyFormat('json');

        return $this;
    }

    private function comoDireccion(): self
    {
        return $this->como('direccion@demo.test', 'fb-demo-direccion-001');
    }

    private function cuerpo(TestResponse $r): array
    {
        return json_decode($r->response()->getJSON(), true);
    }

    // ── SI-01: capturar un lote sincroniza cada acuerdo creado ────────────

    public function testSI01LoteSincronizaCadaAcuerdoCreado(): void
    {
        $manana = Time::now()->addDays(1)->toDateString();
        $r      = $this->comoDireccion()->post('api/v1/acuerdos/lote', [
            'reunion'  => ['nombre' => 'Reunión SI', 'fecha' => Time::now()->toDateString()],
            'acuerdos' => [
                ['tema' => 'Uno', 'accion' => 'Acción uno', 'responsable_id' => 4, 'corresponsables_ids' => [], 'area_id' => 1, 'fecha_compromiso' => $manana, 'enlace' => null, 'observaciones' => null, 'recordatorio_dias' => null],
                ['tema' => 'Dos', 'accion' => 'Acción dos', 'responsable_id' => 5, 'corresponsables_ids' => [], 'area_id' => 2, 'fecha_compromiso' => $manana, 'enlace' => null, 'observaciones' => null, 'recordatorio_dias' => null],
            ],
        ]);

        $r->assertStatus(201);
        $ids = array_map(static fn (array $a) => $a['id'], $this->cuerpo($r)['data']);
        $this->assertSame(2, $this->calendar->llamadas());
        $this->assertSame($ids, $this->calendar->sincronizados);
    }

    // ── SI-02: concluir sincroniza (título [Concluido]/color en el evento) ─

    public function testSI02ConcluirSincronizaElAcuerdo(): void
    {
        $r = $this->comoDireccion()->patch('api/v1/acuerdos/4/concluir', ['nota' => 'ok']);

        $r->assertStatus(200);
        $this->assertContains(4, $this->calendar->sincronizados);
    }

    // ── SI-03: avance CON nueva_fecha sincroniza; sin ella NO ─────────────

    public function testSI03ReprogramacionSincronizaYAvanceSimpleNo(): void
    {
        $manana = Time::now()->addDays(1)->toDateString();

        // Avance simple (sin nueva_fecha): la fecha del evento no cambia → sin llamada.
        $r = $this->comoDireccion()->post('api/v1/acuerdos/5/avances', ['descripcion' => 'Avance simple']);
        $r->assertStatus(200);
        $this->assertSame(0, $this->calendar->llamadas());

        // Reprogramación: el evento debe moverse → una llamada.
        $r = $this->comoDireccion()->post('api/v1/acuerdos/5/avances', [
            'descripcion' => 'Se reprograma',
            'nueva_fecha' => $manana,
        ]);
        $r->assertStatus(200);
        $this->assertSame([5], $this->calendar->sincronizados);
    }

    // ── SI-04: un 403 (sin permiso) NO dispara sincronización ─────────────

    public function testSI04EscrituraRechazadaNoSincroniza(): void
    {
        $r = $this->como('responsable.uno@demo.test', 'fb-demo-resp-001')->patch('api/v1/acuerdos/4/concluir', ['nota' => 'x']);

        $r->assertStatus(403);
        $this->assertSame(0, $this->calendar->llamadas());
    }
}
