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

/**
 * Conclusión / reapertura de acuerdos y checklist de validación (Tarea 7 /
 * S1.6, RF-06). Regla central no negociable (CLAUDE.md): **solo Dirección
 * concluye/reabre**; cualquier otro rol → 403 CON registro del intento en
 * `auditoria`. `estado` nunca del cliente; transacciones multi-tabla;
 * respeta el CHECK `chk_concluido_consistente` del DDL.
 *
 * IDs de caso (ME-07..12, AU-07) referencian el doc 06 (plan de pruebas).
 *
 * @group database
 *
 * @internal
 */
final class ConclusionReaperturaTest extends CIUnitTestCase
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

    private function avancesDe(int $acuerdoId): array
    {
        return Database::connect()->table('avances')->where('acuerdo_id', $acuerdoId)->get()->getResultArray();
    }

    private function auditoriaDe(string $accion, int $acuerdoId): array
    {
        return Database::connect()->table('auditoria')
            ->where('accion', $accion)->where('entidad', 'acuerdo')->where('entidad_id', $acuerdoId)
            ->get()->getResultArray();
    }

    // ── ME-07: Dirección concluye un en_proceso → concluido + autor/fecha + avance validacion ──

    public function testME07DireccionConcluyeEnProceso(): void
    {
        // Acuerdo 4: en_proceso en el seed (área 1, responsable 5).
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4/concluir', ['nota' => 'Evidencia revisada y validada.']);

        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('concluido', $data['estado']);
        $this->assertNotNull($data['concluido_por']);
        $this->assertSame(1, $data['concluido_por']['id']);
        $this->assertNotNull($data['concluido_at']);

        // Fila cruda coherente con el CHECK del DDL.
        $fila = Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray();
        $this->assertSame('concluido', $fila['estado']);
        $this->assertSame(1, (int) $fila['concluido_por_id']);
        $this->assertNotNull($fila['concluido_at']);

        // Avance tipo 'validacion' con la nota.
        $avances = $this->avancesDe(4);
        $validaciones = array_values(array_filter($avances, static fn (array $a) => $a['tipo'] === 'validacion'));
        $this->assertCount(1, $validaciones);
        $this->assertSame('Evidencia revisada y validada.', $validaciones[0]['descripcion']);

        // google_sync marcado pendiente.
        $sync = Database::connect()->table('google_sync')->where('acuerdo_id', 4)->get()->getRowArray();
        $this->assertSame('pendiente', $sync['estado']);

        // Auditoría 'concluir'.
        $this->assertCount(1, $this->auditoriaDe('concluir', 4));
    }

    // ── ME-08: Dirección concluye un vencido → concluido ──────────────────

    public function testME08DireccionConcluyeVencido(): void
    {
        // Acuerdo 3: vencido en el seed.
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/3/concluir', ['nota' => 'Se cierra pese al retraso.']);

        $r->assertStatus(200);
        $this->assertSame('concluido', $this->cuerpo($r)['data']['estado']);

        $fila = Database::connect()->table('acuerdos')->where('id', 3)->get()->getRowArray();
        $this->assertSame('concluido', $fila['estado']);
        $this->assertSame(1, (int) $fila['concluido_por_id']);
    }

    // ── ME-09: concluir un concluido → 409 ────────────────────────────────

    public function testME09ConcluirUnConcluidoEs409(): void
    {
        // Acuerdo 1: concluido en el seed.
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/1/concluir', ['nota' => 'x']);

        $r->assertStatus(409);
        $this->assertSame('estado_invalido', $this->cuerpo($r)['error']);
    }

    // ── ME-10: Dirección reabre un concluido → en_proceso; sin nota → 422 ──

    public function testME10DireccionReabreConcluido(): void
    {
        // Acuerdo 1: concluido (fecha 2026-06-26, ya pasada) → al reabrir se lee como vencido.
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/1/reabrir', ['nota' => 'Se detectó pendiente adicional.']);

        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];
        $this->assertContains($data['estado'], ['en_proceso', 'vencido']);
        $this->assertNull($data['concluido_por']);
        $this->assertNull($data['concluido_at']);

        // Fila cruda: estado en_proceso, concluido_* limpiados (CHECK del DDL).
        $fila = Database::connect()->table('acuerdos')->where('id', 1)->get()->getRowArray();
        $this->assertSame('en_proceso', $fila['estado']);
        $this->assertNull($fila['concluido_por_id']);
        $this->assertNull($fila['concluido_at']);

        // Fecha ya pasada (2026-06-26) → estado derivado en lectura es 'vencido'.
        $this->assertSame('vencido', $data['estado']);

        // Avance 'reapertura'.
        $avances = $this->avancesDe(1);
        $reaperturas = array_values(array_filter($avances, static fn (array $a) => $a['tipo'] === 'reapertura'));
        $this->assertCount(1, $reaperturas);

        $this->assertCount(1, $this->auditoriaDe('reabrir', 1));

        $sync = Database::connect()->table('google_sync')->where('acuerdo_id', 1)->get()->getRowArray();
        $this->assertSame('pendiente', $sync['estado']);
    }

    public function testME10ReabrirSinNotaEs422(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/1/reabrir', ['nota' => '   ']);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('nota', $cuerpo['campos']);

        // No debe haber cambiado nada: sigue concluido.
        $fila = Database::connect()->table('acuerdos')->where('id', 1)->get()->getRowArray();
        $this->assertSame('concluido', $fila['estado']);
    }

    public function testReabrirUnNoConcluidoEs409(): void
    {
        // Acuerdo 4: en_proceso.
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4/reabrir', ['nota' => 'x']);

        $r->assertStatus(409);
        $this->assertSame('estado_invalido', $this->cuerpo($r)['error']);
    }

    // ── ME-12 (ADR-012): coordinación concluye SU área; otra área / responsable → 403 auditado ──

    public function testME12CoordinadorConcluyeAcuerdoDeSuArea(): void
    {
        // Carla (coordinadora, área 1) concluye el acuerdo 4 (área 1) — permitido (ADR-012).
        $r = $this->como('coordinacion.operativa@demo.test')->patch('api/v1/acuerdos/4/concluir', ['nota' => 'Validado por coordinación.']);

        $r->assertStatus(200);

        // La fila quedó concluida con Carla como autora.
        $despues = Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray();
        $this->assertSame('concluido', $despues['estado']);
        $this->assertSame(2, (int) $despues['concluido_por_id']); // Carla = id 2.

        // Auditoría 'concluir' (no un intento denegado).
        $this->assertCount(1, $this->auditoriaDe('concluir', 4));
        $this->assertCount(0, $this->auditoriaDe('intento_concluir', 4));
    }

    public function testME12CoordinadorConcluirOtraAreaEs403YAuditaElIntento(): void
    {
        $antes = Database::connect()->table('acuerdos')->where('id', 5)->get()->getRowArray();

        // Carla (área 1) intenta concluir el acuerdo 5 (área 2) — 403 + auditoría.
        $r = $this->como('coordinacion.operativa@demo.test')->patch('api/v1/acuerdos/5/concluir', ['nota' => 'no debería poder']);

        $r->assertStatus(403);
        $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);

        // La fila NO cambió: sigue abierta, sin autor de conclusión.
        $despues = Database::connect()->table('acuerdos')->where('id', 5)->get()->getRowArray();
        $this->assertSame($antes['estado'], $despues['estado']);
        $this->assertNull($despues['concluido_por_id']);

        $intentos = $this->auditoriaDe('intento_concluir', 5);
        $this->assertCount(1, $intentos);
        $this->assertSame(2, (int) $intentos[0]['usuario_id']); // Carla = id 2.
    }

    public function testME12ResponsableConcluirEs403YAuditaElIntento(): void
    {
        // Rafael (responsable, id 5) es el responsable del acuerdo 4 — pero no es Dirección.
        $r = $this->como('responsable.dos@demo.test')->patch('api/v1/acuerdos/4/concluir', ['nota' => 'no debería poder']);

        $r->assertStatus(403);
        $intentos = $this->auditoriaDe('intento_concluir', 4);
        $this->assertCount(1, $intentos);
        $this->assertSame(5, (int) $intentos[0]['usuario_id']);
    }

    public function testME12CoordinadorReabrirEs403YAuditaElIntento(): void
    {
        // Acuerdo 1: concluido. Carla intenta reabrirlo → 403 + auditoría.
        $r = $this->como('coordinacion.operativa@demo.test')->patch('api/v1/acuerdos/1/reabrir', ['nota' => 'no debería poder']);

        $r->assertStatus(403);
        $this->assertCount(1, $this->auditoriaDe('intento_reabrir', 1));

        // Sigue concluido.
        $fila = Database::connect()->table('acuerdos')->where('id', 1)->get()->getRowArray();
        $this->assertSame('concluido', $fila['estado']);
    }

    // ── concluir con body que trae estado → 422 campo_no_permitido ────────

    public function testConcluirConCampoEstadoEs422CampoNoPermitido(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4/concluir', ['estado' => 'en_proceso']);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    // ── concluir acuerdo inexistente → 404 ────────────────────────────────

    public function testConcluirAcuerdoInexistenteEs404(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/9999/concluir', ['nota' => 'x']);

        $r->assertStatus(404);
    }

    // ── AU-07 (ADR-012): coordinación ve el checklist de SU área; responsable → 403 ──

    public function testAU07ChecklistCoordinadorSoloSuAreaResponsable403(): void
    {
        // Carla (coordinadora, área 1) ve el checklist filtrado a su área.
        $r = $this->como('coordinacion.operativa@demo.test')->get('api/v1/checklist');
        $r->assertStatus(200);
        $ids = array_map(static fn (array $i) => (int) $i['acuerdo']['id'], $this->cuerpo($r)['data']);
        // No aparecen acuerdos de otra área (5,6,7 son de área 2); sí los abiertos de área 1 (p.ej. 4).
        $this->assertNotContains(5, $ids);
        $this->assertNotContains(6, $ids);
        $this->assertNotContains(7, $ids);
        $this->assertContains(4, $ids);

        // Un responsable sigue sin acceso al checklist.
        $r2 = $this->como('responsable.uno@demo.test')->get('api/v1/checklist');
        $r2->assertStatus(403);
    }

    // ── checklist: solo abiertos, vencidos primero, forma correcta ────────

    public function testChecklistSoloAbiertosVencidosPrimeroYFormaCorrecta(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/checklist');

        $r->assertStatus(200);
        $items = $this->cuerpo($r)['data'];
        $this->assertNotEmpty($items);

        // Ningún concluido (acuerdos 1 y 2 en el seed) aparece.
        $ids = array_map(static fn (array $i) => $i['acuerdo']['id'], $items);
        $this->assertNotContains(1, $ids);
        $this->assertNotContains(2, $ids);

        // Forma de cada item.
        foreach ($items as $item) {
            $this->assertArrayHasKey('acuerdo', $item);
            $this->assertArrayHasKey('total_avances', $item);
            $this->assertArrayHasKey('ultimo_avance', $item);
            $this->assertIsInt($item['total_avances']);
            $this->assertNotSame('concluido', $item['acuerdo']['estado']);
        }

        // Priorización: todos los vencidos aparecen antes que el primer en_proceso.
        $primerEnProceso = null;
        foreach ($items as $idx => $item) {
            if ($item['acuerdo']['estado'] === 'en_proceso') {
                $primerEnProceso = $idx;
                break;
            }
        }
        if ($primerEnProceso !== null) {
            for ($i = $primerEnProceso; $i < count($items); $i++) {
                $this->assertNotSame('vencido', $items[$i]['acuerdo']['estado'], 'Un vencido no debe aparecer después de un en_proceso.');
            }
        }

        // total_avances / ultimo_avance correctos para un acuerdo con avances (3 tiene 1 avance en el seed).
        $item3 = null;
        foreach ($items as $item) {
            if ($item['acuerdo']['id'] === 3) {
                $item3 = $item;
                break;
            }
        }
        $this->assertNotNull($item3);
        $this->assertSame(1, $item3['total_avances']);
        $this->assertNotNull($item3['ultimo_avance']);
        $this->assertArrayHasKey('tipo', $item3['ultimo_avance']);
    }

    public function testChecklistUltimoAvanceEsNullSiNoHayAvances(): void
    {
        // Acuerdo 5: en_proceso, sin avances en el seed.
        $r = $this->como('direccion@demo.test')->get('api/v1/checklist');
        $items = $this->cuerpo($r)['data'];

        $item5 = null;
        foreach ($items as $item) {
            if ($item['acuerdo']['id'] === 5) {
                $item5 = $item;
                break;
            }
        }
        $this->assertNotNull($item5);
        $this->assertSame(0, $item5['total_avances']);
        $this->assertNull($item5['ultimo_avance']);
    }
}
