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
use Tests\Support\FakeMailer;
use Tests\Support\FakeTokenVerifier;

/**
 * Notificación inmediata de asignación (ADR-010): al capturar un lote se
 * envía un correo al responsable y a cada corresponsable activo, registrado
 * en `recordatorios_enviados` con tipo `asignacion`. Casos NA-01..NA-03.
 *
 * @group database
 *
 * @internal
 */
final class NotificacionAsignacionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private FakeTokenVerifier $fake;
    private FakeMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeTokenVerifier();
        Services::injectMock('tokenVerifier', $this->fake);
        $this->mailer = new FakeMailer();
        Services::injectMock('mailer', $this->mailer);
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function comoDireccion(): self
    {
        $this->fake->exito('fb-demo-direccion-001', 'direccion@demo.test', true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->withBodyFormat('json');

        return $this;
    }

    private function cuerpo(TestResponse $r): array
    {
        return json_decode($r->response()->getJSON(), true);
    }

    private function capturar(array $acuerdo): TestResponse
    {
        return $this->comoDireccion()->post('api/v1/acuerdos/lote', [
            'reunion'  => ['nombre' => 'Reunión NA', 'fecha' => Time::now()->toDateString()],
            'acuerdos' => [$acuerdo + [
                'tema'                => 'Notif',
                'corresponsables_ids' => [],
                'area_id'             => 1,
                'fecha_compromiso'    => Time::now()->addDays(3)->toDateString(),
                'enlace'              => null,
                'observaciones'       => null,
                'recordatorio_dias'   => null,
            ]],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function filasAsignacion(int $acuerdoId): array
    {
        return Database::connect()->table('recordatorios_enviados')
            ->where('acuerdo_id', $acuerdoId)->where('tipo', 'asignacion')
            ->orderBy('usuario_id', 'ASC')
            ->get()->getResultArray();
    }

    // ── NA-01: correo inmediato a responsable Y corresponsables, registrado ──

    public function testNA01NotificaAResponsableYCorresponsablesYRegistra(): void
    {
        // Responsable 4 (Rita) + corresponsable 5 (Rafael).
        $r = $this->capturar(['accion' => 'Acción notificada', 'responsable_id' => 4, 'corresponsables_ids' => [5]]);

        $r->assertStatus(201);
        $acuerdoId = (int) $this->cuerpo($r)['data'][0]['id'];

        $this->assertSame(1, $this->mailer->contarPara('responsable.uno@demo.test'));
        $this->assertSame(1, $this->mailer->contarPara('responsable.dos@demo.test'));
        $this->assertStringStartsWith('Nuevo acuerdo asignado:', $this->mailer->enviados[0]['asunto']);

        $filas = $this->filasAsignacion($acuerdoId);
        $this->assertCount(2, $filas);
        $this->assertSame(['enviado', 'enviado'], array_column($filas, 'estado'));
        $this->assertNotEmpty($filas[0]['gmail_message_id']);
    }

    // ── NA-02: fallo con un destinatario no bloquea al otro (fila fallido) ──

    public function testNA02FalloDeUnDestinatarioNoBloqueaAlOtro(): void
    {
        $this->mailer->fallaPara('responsable.uno@demo.test');

        $r = $this->capturar(['accion' => 'Acción con fallo', 'responsable_id' => 4, 'corresponsables_ids' => [5]]);

        $r->assertStatus(201, 'el fallo del correo jamás rompe la captura');
        $acuerdoId = (int) $this->cuerpo($r)['data'][0]['id'];

        $filas = $this->filasAsignacion($acuerdoId);
        $this->assertCount(2, $filas);
        $porUsuario = array_column($filas, 'estado', 'usuario_id');
        $this->assertSame('fallido', $porUsuario[4]);
        $this->assertSame('enviado', $porUsuario[5]);
        $this->assertSame(1, $this->mailer->contarPara('responsable.dos@demo.test'));
    }

    // ── NA-03: sin corresponsables, un solo correo (solo responsable) ──────

    public function testNA03SinCorresponsablesSoloNotificaAlResponsable(): void
    {
        $r = $this->capturar(['accion' => 'Acción solitaria', 'responsable_id' => 6]);

        $r->assertStatus(201);
        $acuerdoId = (int) $this->cuerpo($r)['data'][0]['id'];

        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame(1, $this->mailer->contarPara('responsable.tres@demo.test'));
        $this->assertCount(1, $this->filasAsignacion($acuerdoId));
    }
}
