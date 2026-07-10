<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use App\Libraries\Auth\AuthCache;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Tests\Support\FakeTokenVerifier;

/**
 * Guardia central `cuenta_pendiente` (ADR-006, "Registro A"): un usuario con
 * `rol: 'pendiente'` solo puede usar `GET/PATCH /me`; cualquier otra ruta del
 * grupo protegido responde 403 `cuenta_pendiente`. Al asignarle un rol
 * operativo (`PATCH /usuarios/{id}` por Dirección), el AuthCache se invalida
 * y la siguiente request ya tiene acceso normal.
 *
 * @group database
 *
 * @internal
 */
final class PendienteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private const UID_DIRECCION = 'fb-demo-direccion-001';
    private const EMAIL_DIRECCION = 'direccion@demo.test';

    private const UID_PENDIENTE   = 'fb-pendiente-001';
    private const EMAIL_PENDIENTE = 'pendiente.persona@demo.test';

    private FakeTokenVerifier $fake;

    private int $pendienteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeTokenVerifier();
        Services::injectMock('tokenVerifier', $this->fake);
        service('cache')->clean();

        // Crea la cuenta pendiente vía el propio endpoint de registro (mismo
        // camino que en producción) antes de cada test.
        $this->fake->exito(self::UID_PENDIENTE, self::EMAIL_PENDIENTE, true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->withBodyFormat('json');
        $alta = $this->post('api/v1/registro', ['nombre' => 'Persona Pendiente']);
        $alta->assertStatus(201);
        $this->pendienteId = (int) json_decode($alta->response()->getJSON(), true)['data']['id'];
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function comoPendiente(): self
    {
        $this->fake->exito(self::UID_PENDIENTE, self::EMAIL_PENDIENTE, true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->withBodyFormat('json');

        return $this;
    }

    private function comoDireccion(): self
    {
        $this->fake->exito(self::UID_DIRECCION, self::EMAIL_DIRECCION, true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->withBodyFormat('json');

        return $this;
    }

    private function cuerpo(TestResponse $r): array
    {
        return json_decode($r->response()->getJSON(), true);
    }

    // ── GET /me → 200 con rol pendiente ────────────────────────────────────

    public function testGetMeEs200ConRolPendiente(): void
    {
        $r = $this->comoPendiente()->get('api/v1/me');

        $r->assertStatus(200);
        $this->assertSame('pendiente', $this->cuerpo($r)['usuario']['rol']);
    }

    // ── El resto de la API → 403 cuenta_pendiente ──────────────────────────

    public function testGetAcuerdosEs403CuentaPendiente(): void
    {
        $r = $this->comoPendiente()->get('api/v1/acuerdos');

        $r->assertStatus(403);
        $this->assertSame('cuenta_pendiente', $this->cuerpo($r)['error']);
    }

    public function testPostAcuerdosLoteEs403CuentaPendiente(): void
    {
        $r = $this->comoPendiente()->post('api/v1/acuerdos/lote', [
            'reunion'  => ['nombre' => 'X', 'fecha' => '2026-07-10'],
            'acuerdos' => [],
        ]);

        $r->assertStatus(403);
        $this->assertSame('cuenta_pendiente', $this->cuerpo($r)['error']);
    }

    public function testGetUsuariosEs403CuentaPendiente(): void
    {
        $r = $this->comoPendiente()->get('api/v1/usuarios');

        $r->assertStatus(403);
        $this->assertSame('cuenta_pendiente', $this->cuerpo($r)['error']);
    }

    public function testGetResumenEs403CuentaPendiente(): void
    {
        $r = $this->comoPendiente()->get('api/v1/resumen');

        $r->assertStatus(403);
        $this->assertSame('cuenta_pendiente', $this->cuerpo($r)['error']);
    }

    // ── PATCH /me sí funciona para una cuenta pendiente ────────────────────

    public function testPatchMeEs200ParaCuentaPendiente(): void
    {
        $r = $this->comoPendiente()->patch('api/v1/me', ['nombre' => 'Nombre Corregido']);

        $r->assertStatus(200);
        $this->assertSame('Nombre Corregido', $this->cuerpo($r)['data']['nombre']);
    }

    // ── Dirección asigna rol → acceso inmediato (AuthCache invalidado) ─────

    public function testTrasAsignarRolElExPendienteAccedeInmediatamente(): void
    {
        // 1) La cuenta pendiente intenta /acuerdos y recibe 403 (queda cacheada como pendiente).
        $antes = $this->comoPendiente()->get('api/v1/acuerdos');
        $antes->assertStatus(403);

        // 2) Dirección le asigna el rol de responsable.
        $asignar = $this->comoDireccion()->patch("api/v1/usuarios/{$this->pendienteId}", ['rol' => 'responsable']);
        $asignar->assertStatus(200);
        $this->assertSame('responsable', $this->cuerpo($asignar)['data']['rol']);

        // 3) La SIGUIENTE request del ex-pendiente a /acuerdos ya es 200 (no espera el TTL del cache).
        $r = $this->comoPendiente()->get('api/v1/acuerdos');
        $r->assertStatus(200);
    }

    public function testAuthCacheSeInvalidaTrasAsignarRol(): void
    {
        // Verificación directa (complementaria) de que AuthCache::invalidar() corrió.
        $this->comoPendiente()->get('api/v1/me');
        $this->comoDireccion()->patch("api/v1/usuarios/{$this->pendienteId}", ['rol' => 'coordinador', 'area_id' => 1]);

        $this->assertNull(AuthCache::obtenerPorUid(self::UID_PENDIENTE));
    }
}
