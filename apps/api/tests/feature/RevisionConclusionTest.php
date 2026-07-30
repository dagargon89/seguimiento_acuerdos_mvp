<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Tests\Support\FakeTokenVerifier;
use Tests\Support\FechaFijaTrait;

/**
 * Solicitud de conclusión (spec 2026-07-29, revisión de conclusión): un
 * responsable o corresponsable pide marcar el acuerdo como concluido; el
 * acuerdo queda en `revision_estado='pendiente'` (Dirección/coordinación
 * concluyen directo — ADR-012 — y NO pasan por este flujo). Todo intento
 * denegado se AUDITA (`intento_solicitar_conclusion`).
 *
 * @group database
 *
 * @internal
 */
final class RevisionConclusionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use FechaFijaTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private const UID = [
        'direccion@demo.test'                => 'fb-demo-direccion-001',
        'coordinacion.operativa@demo.test'   => 'fb-demo-coord-001',
        'coordinacion.vinculacion@demo.test' => 'fb-demo-coord-002',
        'responsable.uno@demo.test'          => 'fb-demo-resp-001',
        'responsable.dos@demo.test'          => 'fb-demo-resp-002',
        'responsable.tres@demo.test'         => 'fb-demo-resp-003',
    ];

    private FakeTokenVerifier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeTokenVerifier();
        Services::injectMock('tokenVerifier', $this->fake);
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        Services::reset();
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

    private function auditoriaDe(string $accion, int $acuerdoId): array
    {
        return Database::connect()->table('auditoria')
            ->where('accion', $accion)->where('entidad', 'acuerdo')->where('entidad_id', $acuerdoId)
            ->get()->getResultArray();
    }

    public function testResponsableSolicitaConclusion(): void
    {
        // Acuerdo 4: en_proceso, responsable = id 5 (responsable.dos).
        $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', ['comentario' => 'Ya terminé la evidencia.']);

        $r->assertStatus(200);
        $this->assertSame('pendiente', $this->cuerpo($r)['data']['revision_estado']);

        $fila = Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray();
        $this->assertSame('pendiente', $fila['revision_estado']);
        $this->assertSame(5, (int) $fila['revision_solicitada_por_id']);
        $this->assertNotNull($fila['revision_solicitada_at']);

        $this->assertCount(1, $this->auditoriaDe('solicitar_conclusion', 4));
    }

    public function testDireccionNoParticipanteNoPuedeSolicitarEs403Auditado(): void
    {
        // Dirección (id 1) no es responsable ni corresponsable del acuerdo 4.
        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);

        $r->assertStatus(403);
        $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);
        $this->assertSame('sin_solicitud', Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray()['revision_estado']);
        $this->assertCount(1, $this->auditoriaDe('intento_solicitar_conclusion', 4));
    }

    public function testSolicitarSobreConcluidoSinPermisoNoQuedaPendiente(): void
    {
        // Acuerdo 1: concluido en el seed. Aun así, probamos con su responsable.
        $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/1/solicitar-conclusion', []);
        // responsable.dos no es responsable de 1 → 403; para el 409 usamos un acuerdo suyo ya concluido no hay en seed,
        // así que validamos el 409 tras aprobar en Task 4. Aquí solo exigimos que NO quede 'pendiente'.
        $this->assertNotSame('pendiente', Database::connect()->table('acuerdos')->where('id', 1)->get()->getRowArray()['revision_estado']);
    }

    public function testAprobarConclusionLimpiaRevisionYAudita(): void
    {
        // Solicitud previa sobre el acuerdo 4 (responsable id 5).
        $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);

        // Dirección aprueba concluyendo.
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4/concluir', ['nota' => 'Aprobado.']);
        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('concluido', $data['estado']);
        $this->assertSame('sin_solicitud', $data['revision_estado']);

        $this->assertCount(1, $this->auditoriaDe('aprobar_conclusion', 4));

        // Solicitar sobre el ya concluido → 409 (cierra el caso pendiente de Task 3).
        $r2 = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);
        $r2->assertStatus(409);
    }

    public function testCoordinadorRechazaConMotivo(): void
    {
        $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);

        // Carla (coordinadora, área 1) rechaza el acuerdo 4 (área 1).
        $r = $this->como('coordinacion.operativa@demo.test')->post('api/v1/acuerdos/4/rechazar-conclusion', ['motivo' => 'Falta la evidencia firmada.']);
        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('rechazada', $data['revision_estado']);
        $this->assertSame('Falta la evidencia firmada.', $data['revision_motivo_rechazo']);
        $this->assertNotSame('concluido', $data['estado']);
        $this->assertCount(1, $this->auditoriaDe('rechazar_conclusion', 4));
    }

    public function testRechazarSinPendienteEs409(): void
    {
        // Acuerdo 4 sin solicitud (sin_solicitud) → 409.
        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/4/rechazar-conclusion', ['motivo' => 'x']);
        $r->assertStatus(409);
    }

    public function testRechazarSinMotivoEs422(): void
    {
        $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);
        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/4/rechazar-conclusion', ['motivo' => '   ']);
        $r->assertStatus(422);
        $this->assertArrayHasKey('motivo', $this->cuerpo($r)['campos']);
    }

    public function testResponsableRechazarEs403Auditado(): void
    {
        $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);
        $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/rechazar-conclusion', ['motivo' => 'x']);
        $r->assertStatus(403);
        $this->assertCount(1, $this->auditoriaDe('intento_rechazar_conclusion', 4));
    }

    /**
     * El estado derivado "vencido" (RF-05.2) debe congelarse mientras hay una
     * solicitud de conclusión pendiente: un acuerdo `en_proceso` con fecha
     * pasada y `revision_estado='pendiente'` se sigue leyendo 'en_proceso'.
     * Al perder ese estado 'pendiente' (rechazo), vuelve a leerse 'vencido'.
     */
    public function testAcuerdoEnRevisionPendienteSeCongelaYNoSeLeeVencido(): void
    {
        $this->fijarFechaTest(); // hoy congelado en 2026-07-09 09:00:00

        try {
            $db   = Database::connect();
            $ayer = Time::now()->subDays(1)->toDateString();

            $db->table('acuerdos')->insert([
                'reunion_id'       => 1,
                'area_id'          => 1,
                'tema'             => 'Prueba congelación en revisión',
                'accion'           => 'Acuerdo de prueba revisión congelada',
                'responsable_id'   => 5, // responsable.dos
                'capturado_por_id' => 1,
                'fecha_compromiso' => $ayer,
                'estado'           => 'en_proceso',
                'revision_estado'  => 'sin_solicitud',
                'created_at'       => Time::now()->toDateTimeString(),
            ]);
            $id = (int) $db->insertID();

            // Caso de control: ANTES de solicitar conclusión (revision_estado
            // != 'pendiente'), el detalle sí se lee 'vencido'.
            $antes = $this->cuerpo($this->como('direccion@demo.test')->get("api/v1/acuerdos/{$id}"));
            $this->assertSame('vencido', $antes['data']['estado']);

            // El responsable solicita la conclusión → revision_estado='pendiente'.
            $solicitud = $this->como('responsable.dos@demo.test')->post("api/v1/acuerdos/{$id}/solicitar-conclusion", []);
            $solicitud->assertStatus(200);
            $this->assertSame('pendiente', $this->cuerpo($solicitud)['data']['revision_estado']);

            // Mientras está pendiente, el detalle se lee 'en_proceso' (congelado), NO 'vencido'.
            $pendiente = $this->cuerpo($this->como('direccion@demo.test')->get("api/v1/acuerdos/{$id}"));
            $this->assertSame('en_proceso', $pendiente['data']['estado']);

            // Dirección rechaza la solicitud → revision_estado='rechazada' (ya no 'pendiente').
            $rechazo = $this->como('direccion@demo.test')->post("api/v1/acuerdos/{$id}/rechazar-conclusion", ['motivo' => 'Falta evidencia.']);
            $rechazo->assertStatus(200);

            // Al salir de 'pendiente', vuelve a leerse 'vencido'.
            $despues = $this->cuerpo($this->como('direccion@demo.test')->get("api/v1/acuerdos/{$id}"));
            $this->assertSame('vencido', $despues['data']['estado']);
        } finally {
            $this->resetFechaTest();
        }
    }

    // ── Checklist: partición 'validar' vs 'revision' (spec 2026-07-30) ─────

    public function testChecklistParticionValidarVsRevision(): void
    {
        // Acuerdo 4 (área 1, responsable id 5) pasa a 'pendiente' vía solicitud.
        $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);

        // Vista por defecto (validar): NO incluye el pendiente 4.
        $validar    = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/checklist'));
        $idsValidar = array_map(static fn (array $i) => (int) $i['acuerdo']['id'], $validar['data']);
        $this->assertNotContains(4, $idsValidar);

        // Vista 'revision': solo pendientes → contiene 4 y todos son 'pendiente'.
        $revision    = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/checklist?vista=revision'));
        $idsRevision = array_map(static fn (array $i) => (int) $i['acuerdo']['id'], $revision['data']);
        $this->assertContains(4, $idsRevision);
        foreach ($revision['data'] as $item) {
            $this->assertSame('pendiente', $item['acuerdo']['revision_estado']);
        }
    }

    // ── Índice: filtro por revision_estado (spec 2026-07-30) ──────────────

    public function testIndexFiltroRevisionEstado(): void
    {
        $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/4/solicitar-conclusion', []);

        // ?revision_estado=pendiente → solo pendientes (incluye 4).
        $pend    = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos?revision_estado=pendiente&per_page=200'));
        $idsPend = array_map(static fn (array $a) => (int) $a['id'], $pend['data']);
        $this->assertContains(4, $idsPend);
        foreach ($pend['data'] as $a) {
            $this->assertSame('pendiente', $a['revision_estado']);
        }

        // ?revision_estado=rechazada → no incluye 4 (que está pendiente).
        $rech    = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos?revision_estado=rechazada&per_page=200'));
        $idsRech = array_map(static fn (array $a) => (int) $a['id'], $rech['data']);
        $this->assertNotContains(4, $idsRech);
    }
}
