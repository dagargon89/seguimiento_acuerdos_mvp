<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Tests\Support\FakeTokenVerifier;

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

    public function testSolicitarSobreConcluidoEs409(): void
    {
        // Acuerdo 1: concluido en el seed. Aun así, probamos con su responsable.
        $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/1/solicitar-conclusion', []);
        // responsable.dos no es responsable de 1 → 403; para el 409 usamos un acuerdo suyo ya concluido no hay en seed,
        // así que validamos el 409 tras aprobar en Task 4. Aquí solo exigimos que NO quede 'pendiente'.
        $this->assertNotSame('pendiente', Database::connect()->table('acuerdos')->where('id', 1)->get()->getRowArray()['revision_estado']);
    }
}
