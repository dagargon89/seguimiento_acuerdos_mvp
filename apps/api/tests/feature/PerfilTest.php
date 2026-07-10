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
use Tests\Support\FechaFijaTrait;

/**
 * Perfil self-service (ADR-005, doc 05 §2.1): `PATCH /me` — cualquier usuario
 * activo edita su propio `nombre`; ningún otro campo (email/rol/area_id/activo)
 * es aceptado por esta vía (422 `campo_no_permitido`); el cambio se refleja de
 * inmediato en `GET /me` (AuthCache invalidado) y se audita `editar_perfil`.
 *
 * @group database
 *
 * @internal
 */
final class PerfilTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use FechaFijaTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    /** firebase_uid sembrados en db.json — evita el flujo de "primer login" en los tests. */
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

    // ── Feliz: el propio usuario cambia su nombre → 200 ───────────────────

    public function testEditarMiPerfilNombrePropioEs200YPersiste(): void
    {
        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/me', ['nombre' => 'Nuevo Nombre']);

        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('Nuevo Nombre', $data['nombre']);
        $this->assertSame(4, $data['id']);

        // Persistido en BD.
        $fila = Database::connect()->table('usuarios')->where('id', 4)->get()->getRowArray();
        $this->assertSame('Nuevo Nombre', $fila['nombre']);

        // Auditado.
        $aud = Database::connect()->table('auditoria')->where('accion', 'editar_perfil')->where('entidad_id', 4)->countAllResults();
        $this->assertSame(1, $aud);
    }

    // ── Campos prohibidos: rol/email/area_id/activo → 422 campo_no_permitido, sin cambios ──

    public function testEditarMiPerfilConRolEs422CampoNoPermitidoYRolNoCambia(): void
    {
        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/me', ['nombre' => 'X', 'rol' => 'direccion']);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('campo_no_permitido', $cuerpo['error']);
        $this->assertArrayHasKey('rol', $cuerpo['campos']);

        $fila = Database::connect()->table('usuarios')->where('id', 4)->get()->getRowArray();
        $this->assertSame('responsable', $fila['rol']);
        $this->assertSame('Rita Responsable', $fila['nombre']);
    }

    public function testEditarMiPerfilConEmailEs422CampoNoPermitido(): void
    {
        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/me', ['email' => 'otro@demo.test']);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);

        $fila = Database::connect()->table('usuarios')->where('id', 4)->get()->getRowArray();
        $this->assertSame('responsable.uno@demo.test', $fila['email']);
    }

    public function testEditarMiPerfilConAreaIdEs422CampoNoPermitido(): void
    {
        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/me', ['area_id' => 1]);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    public function testEditarMiPerfilConActivoEs422CampoNoPermitidoYSigueActivo(): void
    {
        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/me', ['activo' => false]);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('campo_no_permitido', $cuerpo['error']);
        $this->assertArrayHasKey('activo', $cuerpo['campos']);

        $fila = Database::connect()->table('usuarios')->where('id', 4)->get()->getRowArray();
        $this->assertSame(1, (int) $fila['activo']);
    }

    // ── Validación: nombre vacío → 422 validacion ─────────────────────────

    public function testEditarMiPerfilNombreVacioEs422Validacion(): void
    {
        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/me', ['nombre' => '  ']);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('nombre', $cuerpo['campos']);

        $fila = Database::connect()->table('usuarios')->where('id', 4)->get()->getRowArray();
        $this->assertSame('Rita Responsable', $fila['nombre']);
    }

    // ── Sin token → 401 (lo cubre el filtro FirebaseAuth) ─────────────────

    public function testEditarMiPerfilSinTokenEs401(): void
    {
        $this->withBodyFormat('json');
        $r = $this->patch('api/v1/me', ['nombre' => 'X']);
        $r->assertStatus(401);
    }

    // ── El cambio se refleja de inmediato en GET /me (AuthCache invalidado) ──

    public function testTrasEditarMiPerfilGetMeReflejaElNombreNuevo(): void
    {
        // 1) Primera request exitosa: queda cacheada la identidad de Rita.
        $ok = $this->como('responsable.uno@demo.test')->get('api/v1/me');
        $ok->assertStatus(200);
        $this->assertSame('Rita Responsable', $this->cuerpo($ok)['usuario']['nombre']);

        // 2) Cambia su nombre.
        $editar = $this->como('responsable.uno@demo.test')->patch('api/v1/me', ['nombre' => 'Rita Renombrada']);
        $editar->assertStatus(200);

        // 3) La siguiente lectura de sesión ya refleja el nombre nuevo (no espera el TTL del cache).
        $r = $this->como('responsable.uno@demo.test')->get('api/v1/me');
        $r->assertStatus(200);
        $this->assertSame('Rita Renombrada', $this->cuerpo($r)['usuario']['nombre']);
    }
}
