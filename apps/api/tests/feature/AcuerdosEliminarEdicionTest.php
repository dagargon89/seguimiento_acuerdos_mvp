<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Tests\Support\FakeCalendarSync;
use Tests\Support\FakeMailer;
use Tests\Support\FakeTokenVerifier;

/**
 * ADR-011: (a) DELETE /acuerdos/{id} — SOLO Dirección, cascada completa,
 * auditoría del borrado y del intento denegado, y eliminación best-effort del
 * evento de calendario; (b) quien CAPTURÓ un acuerdo puede editarlo
 * (PATCH /acuerdos/{id}) aunque no sea Dirección ni coordinación del área.
 * Casos EL-01..03 y EDC-01..02.
 *
 * @group database
 *
 * @internal
 */
final class AcuerdosEliminarEdicionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private const UID = [
        'direccion@demo.test'              => 'fb-demo-direccion-001',
        'coordinacion.operativa@demo.test' => 'fb-demo-coord-001',
        'responsable.uno@demo.test'        => 'fb-demo-resp-001',
        'responsable.dos@demo.test'        => 'fb-demo-resp-002',
    ];

    private FakeTokenVerifier $fake;
    private FakeCalendarSync $calendar;
    private FakeMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeTokenVerifier();
        Services::injectMock('tokenVerifier', $this->fake);
        $this->calendar = new FakeCalendarSync();
        Services::injectMock('calendarSync', $this->calendar);
        $this->mailer = new FakeMailer();
        Services::injectMock('mailer', $this->mailer);
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
        return json_decode($r->response()->getJSON(), true) ?? [];
    }

    private function cuenta(string $tabla, int $acuerdoId, string $col = 'acuerdo_id'): int
    {
        return Database::connect()->table($tabla)->where($col, $acuerdoId)->countAllResults();
    }

    // ── EL-01: Dirección elimina → 204, cascada, auditoría y evento borrado ──

    public function testEL01DireccionEliminaConCascadaAuditoriaYEvento(): void
    {
        $db = Database::connect();
        // El acuerdo 4 (en_proceso) con evento ya sincronizado.
        $db->table('google_sync')->where('acuerdo_id', 4)
            ->update(['calendar_event_id' => 'evt-a-borrar-4', 'estado' => 'sincronizado']);

        $this->assertGreaterThan(0, $this->cuenta('recordatorios_enviados', 4) + $this->cuenta('avances', 4) + 1);

        $r = $this->como('direccion@demo.test')->delete('api/v1/acuerdos/4');
        $r->assertStatus(204);

        $this->assertSame(0, $this->cuenta('acuerdos', 4, 'id'));
        $this->assertSame(0, $this->cuenta('google_sync', 4));
        $this->assertSame(0, $this->cuenta('avances', 4));
        $this->assertSame(0, $this->cuenta('acuerdo_corresponsables', 4));
        $this->assertSame(0, $this->cuenta('recordatorios_enviados', 4));

        $aud = $db->table('auditoria')->where('accion', 'eliminar')->where('entidad_id', 4)->countAllResults();
        $this->assertSame(1, $aud, 'el borrado queda auditado con la ficha del acuerdo');

        $this->assertSame(['evt-a-borrar-4'], $this->calendar->eventosEliminados);

        // Aviso por correo (ADR-011): responsable (5, Rafael) del acuerdo 4 del seed.
        $this->assertGreaterThanOrEqual(1, count($this->mailer->enviados), 'se avisa la eliminación por correo');
        $this->assertStringStartsWith('Acuerdo eliminado:', $this->mailer->enviados[0]['asunto']);
    }

    // ── EL-02: no-Dirección → 403 auditado y el acuerdo sigue ──────────────

    public function testEL02NoDireccionEs403AuditadoYNoBorra(): void
    {
        $r = $this->como('responsable.uno@demo.test')->delete('api/v1/acuerdos/4');

        $r->assertStatus(403);
        $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);
        $this->assertSame(1, $this->cuenta('acuerdos', 4, 'id'));

        $aud = Database::connect()->table('auditoria')
            ->where('accion', 'intento_eliminar')->where('entidad_id', 4)->countAllResults();
        $this->assertSame(1, $aud);
        $this->assertSame([], $this->calendar->eventosEliminados);
    }

    // ── EL-03: id inexistente → 404 ────────────────────────────────────────

    public function testEL03InexistenteEs404(): void
    {
        $r = $this->como('direccion@demo.test')->delete('api/v1/acuerdos/9999');
        $r->assertStatus(404);
    }

    // ── EDC-01: quien capturó el acuerdo puede editarlo ────────────────────

    public function testEDC01CapturadorPuedeEditarSuAcuerdo(): void
    {
        // El acuerdo 5 pasa a "capturado por" Rita (4, responsable sin área).
        Database::connect()->table('acuerdos')->where('id', 5)->update(['capturado_por_id' => 4]);

        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/acuerdos/5', ['accion' => 'Acción corregida por quien la capturó']);

        $r->assertStatus(200);
        $this->assertSame('Acción corregida por quien la capturó', $this->cuerpo($r)['data']['accion']);
    }

    // ── EDC-02: un responsable ajeno (ni capturador, ni área) sigue en 403 ──

    public function testEDC02ResponsableAjenoSigueSinPoderEditar(): void
    {
        Database::connect()->table('acuerdos')->where('id', 5)->update(['capturado_por_id' => 4]);

        $r = $this->como('responsable.dos@demo.test')->patch('api/v1/acuerdos/5', ['accion' => 'Intento ajeno']);

        $r->assertStatus(403);
    }
}
