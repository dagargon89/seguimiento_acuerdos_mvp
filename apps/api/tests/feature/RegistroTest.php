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
 * Autorregistro (ADR-006, "Registro A") — `POST /registro`: cualquier portador
 * de un ID token Firebase válido puede darse de alta SIN lista blanca
 * (`firebaseauth:sin_lista`); uid/email SIEMPRE del token, jamás del body;
 * body solo acepta `nombre`; 409 si el uid o el email ya están en `usuarios`;
 * la fila nace con `rol: 'pendiente'` y se audita `registro_usuario`.
 *
 * @group database
 *
 * @internal
 */
final class RegistroTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    /** firebase_uid/email sembrados en db.json — usados para provocar los 409. */
    private const UID_SEMBRADO      = 'fb-demo-resp-001';
    private const EMAIL_SEMBRADO    = 'responsable.uno@demo.test';

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

    /** Token válido para un uid/email que NO existe en el seed (candidato a autorregistro). */
    private function comoNuevo(string $uid = 'fb-nueva-001', string $email = 'nueva.persona@demo.test'): self
    {
        $this->fake->exito($uid, $email, true);
        $this->withHeaders(['Authorization' => 'Bearer token-valido']);
        $this->withBodyFormat('json');

        return $this;
    }

    private function cuerpo(TestResponse $r): array
    {
        return json_decode($r->response()->getJSON(), true);
    }

    // ── Feliz: 201, rol pendiente, uid del token, auditoría ───────────────

    public function testRegistroFelizEs201ConRolPendienteYUidDelToken(): void
    {
        $r = $this->comoNuevo()->post('api/v1/registro', ['nombre' => 'Nueva Persona']);

        $r->assertStatus(201);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('Nueva Persona', $data['nombre']);
        $this->assertSame('nueva.persona@demo.test', $data['email']);
        $this->assertSame('pendiente', $data['rol']);
        $this->assertNull($data['area_id']);
        $this->assertTrue($data['activo']);

        $fila = Database::connect()->table('usuarios')->where('email', 'nueva.persona@demo.test')->get()->getRowArray();
        $this->assertNotNull($fila);
        $this->assertSame('fb-nueva-001', $fila['firebase_uid']);
        $this->assertSame('pendiente', $fila['rol']);

        $aud = Database::connect()->table('auditoria')->where('accion', 'registro_usuario')->where('entidad_id', (int) $fila['id'])->countAllResults();
        $this->assertSame(1, $aud);
        $auditoria = Database::connect()->table('auditoria')->where('accion', 'registro_usuario')->where('entidad_id', (int) $fila['id'])->get()->getRowArray();
        $this->assertSame((int) $fila['id'], (int) $auditoria['usuario_id']);
    }

    // ── uid/email SIEMPRE del token, nunca del body (aunque el body los incluyera, serían 422 campo_no_permitido) ──

    public function testRegistroIgnoraCamposFueraDeNombreConCampoNoPermitido(): void
    {
        $r = $this->comoNuevo()->post('api/v1/registro', ['nombre' => 'X', 'email' => 'otra@demo.test']);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('campo_no_permitido', $cuerpo['error']);
        $this->assertArrayHasKey('email', $cuerpo['campos']);
    }

    public function testRegistroConRolEnBodyEs422CampoNoPermitido(): void
    {
        $r = $this->comoNuevo()->post('api/v1/registro', ['nombre' => 'X', 'rol' => 'direccion']);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('campo_no_permitido', $cuerpo['error']);
        $this->assertArrayHasKey('rol', $cuerpo['campos']);
    }

    public function testRegistroConEstadoEnBodyEs422CampoNoPermitido(): void
    {
        $r = $this->comoNuevo()->post('api/v1/registro', ['nombre' => 'X', 'estado' => 'activo']);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    // ── Email ya en usuarios → 409 ─────────────────────────────────────────

    public function testEmailYaExistenteEs409CuentaYaExiste(): void
    {
        $r = $this->comoNuevo('fb-otro-uid', self::EMAIL_SEMBRADO)->post('api/v1/registro', ['nombre' => 'Intento']);

        $r->assertStatus(409);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('cuenta_ya_existe', $cuerpo['error']);

        // No se insertó una fila nueva con ese uid.
        $filas = Database::connect()->table('usuarios')->where('firebase_uid', 'fb-otro-uid')->countAllResults();
        $this->assertSame(0, $filas);
    }

    // ── uid ya enlazado → 409 ──────────────────────────────────────────────

    public function testUidYaEnlazadoEs409CuentaYaExiste(): void
    {
        $r = $this->comoNuevo(self::UID_SEMBRADO, 'correo.distinto@demo.test')->post('api/v1/registro', ['nombre' => 'Intento']);

        $r->assertStatus(409);
        $this->assertSame('cuenta_ya_existe', $this->cuerpo($r)['error']);

        $filas = Database::connect()->table('usuarios')->where('email', 'correo.distinto@demo.test')->countAllResults();
        $this->assertSame(0, $filas);
    }

    // ── Sin token → 401 ─────────────────────────────────────────────────────

    public function testSinTokenEs401(): void
    {
        $this->withBodyFormat('json');
        $r = $this->post('api/v1/registro', ['nombre' => 'X']);
        $r->assertStatus(401);
        $this->assertSame('token_faltante', $this->cuerpo($r)['error']);
    }

    public function testTokenInvalidoEs401(): void
    {
        $this->fake->rechaza('expirado');
        $this->withHeaders(['Authorization' => 'Bearer token-cualquiera']);
        $this->withBodyFormat('json');
        $r = $this->post('api/v1/registro', ['nombre' => 'X']);
        $r->assertStatus(401);
        $this->assertSame('token_invalido', $this->cuerpo($r)['error']);
    }

    // ── nombre vacío → 422 validacion ──────────────────────────────────────

    public function testNombreVacioEs422Validacion(): void
    {
        $r = $this->comoNuevo()->post('api/v1/registro', ['nombre' => '   ']);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('nombre', $cuerpo['campos']);
    }

    public function testNombreAusenteEs422Validacion(): void
    {
        $r = $this->comoNuevo()->post('api/v1/registro', []);

        $r->assertStatus(422);
        $this->assertSame('validacion', $this->cuerpo($r)['error']);
    }

    public function testNombreDemasiadoLargoEs422Validacion(): void
    {
        $r = $this->comoNuevo()->post('api/v1/registro', ['nombre' => str_repeat('a', 121)]);

        $r->assertStatus(422);
        $this->assertArrayHasKey('nombre', $this->cuerpo($r)['campos']);
    }
}
