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
 * Administración de usuarios y áreas (Tarea 7 / S1.6, RF-10, ADR-004). Solo
 * Dirección da de alta/edita (403 en otros roles); email/nombre únicos (422);
 * no se desactiva a la última Dirección; baja lógica conserva historial; la
 * desactivación invalida el AuthCache (efecto ≤60 s, AD-05 / AU-10).
 *
 * IDs de caso (AU-07, AD-01..05, AR-01..04) referencian el doc 06.
 *
 * @group database
 *
 * @internal
 */
final class AdminUsuariosAreasTest extends CIUnitTestCase
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

    // ══════════════════════════════ USUARIOS ══════════════════════════════

    public function testAltaUsuarioValidoEs201YFormaUsuario(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/usuarios', [
            'nombre' => 'Nueva Persona', 'email' => 'nueva.persona@demo.test', 'rol' => 'responsable', 'area_id' => null,
        ]);

        $r->assertStatus(201);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('Nueva Persona', $data['nombre']);
        $this->assertSame('responsable', $data['rol']);
        $this->assertTrue($data['activo']);
        $this->assertArrayHasKey('id', $data);

        // Persistido y auditado.
        $fila = Database::connect()->table('usuarios')->where('email', 'nueva.persona@demo.test')->get()->getRowArray();
        $this->assertNotNull($fila);
        $aud = Database::connect()->table('auditoria')->where('accion', 'alta_usuario')->where('entidad_id', (int) $fila['id'])->countAllResults();
        $this->assertSame(1, $aud);
    }

    public function testAltaCoordinadorRequiereAreaActiva(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/usuarios', [
            'nombre' => 'Coord Sin Area', 'email' => 'coord.sinarea@demo.test', 'rol' => 'coordinador', 'area_id' => null,
        ]);

        $r->assertStatus(422);
        $this->assertArrayHasKey('area_id', $this->cuerpo($r)['campos']);
    }

    public function testAltaCoordinadorConAreaValidaEs201(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/usuarios', [
            'nombre' => 'Coord Con Area', 'email' => 'coord.conarea@demo.test', 'rol' => 'coordinador', 'area_id' => 1,
        ]);

        $r->assertStatus(201);
        $this->assertSame(1, $this->cuerpo($r)['data']['area_id']);
    }

    // ── AD-01: alta con email duplicado → 422 ─────────────────────────────

    public function testAD01AltaEmailDuplicadoEs422(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/usuarios', [
            'nombre' => 'Duplicada', 'email' => 'responsable.uno@demo.test', 'rol' => 'responsable', 'area_id' => null,
        ]);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('email', $cuerpo['campos']);
    }

    // ── AU-07: rol no-Dirección hace POST/PATCH /usuarios → 403 ───────────

    public function testAU07AltaUsuarioNoDireccionEs403(): void
    {
        $r = $this->como('coordinacion.operativa@demo.test')->post('api/v1/usuarios', [
            'nombre' => 'X', 'email' => 'x@demo.test', 'rol' => 'responsable', 'area_id' => null,
        ]);
        $r->assertStatus(403);
        $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);
    }

    public function testAU07EditarUsuarioNoDireccionEs403(): void
    {
        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/usuarios/5', ['nombre' => 'X']);
        $r->assertStatus(403);
    }

    // ── edición feliz: cambia nombre → 200 ────────────────────────────────

    public function testEditarNombreEs200YAudita(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/usuarios/5', ['nombre' => 'Rafael Renombrado']);

        $r->assertStatus(200);
        $this->assertSame('Rafael Renombrado', $this->cuerpo($r)['data']['nombre']);

        $aud = Database::connect()->table('auditoria')->where('accion', 'editar_usuario')->where('entidad_id', 5)->countAllResults();
        $this->assertSame(1, $aud);
    }

    public function testEditarEmailDuplicadoEs422(): void
    {
        // Intentar poner en el usuario 5 el email del usuario 4.
        $r = $this->como('direccion@demo.test')->patch('api/v1/usuarios/5', ['email' => 'responsable.uno@demo.test']);

        $r->assertStatus(422);
        $this->assertArrayHasKey('email', $this->cuerpo($r)['campos']);
    }

    public function testEditarUsuarioInexistenteEs404(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/usuarios/9999', ['nombre' => 'X']);
        $r->assertStatus(404);
    }

    // ── AD-02: desactivar a la última Dirección → 422 ─────────────────────

    public function testAD02DesactivarUltimaDireccionEs422(): void
    {
        // En el seed hay UNA sola Dirección activa (id 1).
        $r = $this->como('direccion@demo.test')->patch('api/v1/usuarios/1', ['activo' => false]);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('activo', $cuerpo['campos']);

        // Sigue activa.
        $fila = Database::connect()->table('usuarios')->where('id', 1)->get()->getRowArray();
        $this->assertSame(1, (int) $fila['activo']);
    }

    public function testDesactivarDireccionCuandoHayOtraEs200(): void
    {
        // Damos de alta una segunda Dirección para poder desactivar la primera.
        $this->como('direccion@demo.test')->post('api/v1/usuarios', [
            'nombre' => 'Segunda Dirección', 'email' => 'direccion.dos@demo.test', 'rol' => 'direccion', 'area_id' => null,
        ]);

        $r = $this->como('direccion@demo.test')->patch('api/v1/usuarios/1', ['activo' => false]);
        $r->assertStatus(200);
        $this->assertFalse($this->cuerpo($r)['data']['activo']);
    }

    // ── AD-03: baja lógica conserva historial (acuerdos del usuario) ──────

    public function testAD03BajaLogicaConservaAcuerdos(): void
    {
        // Rafael (id 5) es responsable de acuerdos en el seed; la baja NO los borra.
        $antes = Database::connect()->table('acuerdos')->where('responsable_id', 5)->countAllResults();
        $this->assertGreaterThan(0, $antes);

        $r = $this->como('direccion@demo.test')->patch('api/v1/usuarios/5', ['activo' => false]);
        $r->assertStatus(200);
        $this->assertFalse($this->cuerpo($r)['data']['activo']);

        // El usuario sigue existiendo (baja lógica, no física) y sus acuerdos intactos.
        $fila = Database::connect()->table('usuarios')->where('id', 5)->get()->getRowArray();
        $this->assertNotNull($fila);
        $this->assertSame(0, (int) $fila['activo']);
        $despues = Database::connect()->table('acuerdos')->where('responsable_id', 5)->countAllResults();
        $this->assertSame($antes, $despues);
    }

    // ── AD-04: usuario desactivado desaparece de GET /usuarios ────────────

    public function testAD04UsuarioDesactivadoDesapareceDelDirectorio(): void
    {
        // Bruno (id 7) ya está inactivo en el seed → no debe listarse.
        $r = $this->como('direccion@demo.test')->get('api/v1/usuarios');
        $r->assertStatus(200);
        $ids = array_map(static fn (array $u) => $u['id'], $this->cuerpo($r)['data']);
        $this->assertNotContains(7, $ids);

        // Al desactivar el 5, tampoco aparece en el siguiente GET.
        $this->como('direccion@demo.test')->patch('api/v1/usuarios/5', ['activo' => false]);
        $r2 = $this->como('direccion@demo.test')->get('api/v1/usuarios');
        $ids2 = array_map(static fn (array $u) => $u['id'], $this->cuerpo($r2)['data']);
        $this->assertNotContains(5, $ids2);
    }

    // ── AD-05: tras desactivar, la SIGUIENTE request del usuario → 403 (AuthCache invalidado) ──

    public function testAD05DesactivarInvalidaAuthCacheYSiguienteRequestEs403(): void
    {
        // 1) Carla (coordinadora activa, id 2) hace una request exitosa → queda cacheada como activa.
        $ok = $this->como('coordinacion.operativa@demo.test')->get('api/v1/usuarios');
        $ok->assertStatus(200);

        // 2) Dirección la desactiva (esto invalida su AuthCache).
        $baja = $this->como('direccion@demo.test')->patch('api/v1/usuarios/2', ['activo' => false]);
        $baja->assertStatus(200);

        // 3) La siguiente request autenticada de Carla debe fallar con 403 (no espera el TTL de 60 s).
        $r = $this->como('coordinacion.operativa@demo.test')->get('api/v1/usuarios');
        $r->assertStatus(403);
        $this->assertSame('usuario_no_registrado', $this->cuerpo($r)['error']);
    }

    public function testAltaConCampoDesconocidoEs422(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/usuarios', [
            'nombre' => 'X', 'email' => 'x@demo.test', 'rol' => 'responsable', 'area_id' => null, 'activo' => true,
        ]);
        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    // ══════════════════════════════ ÁREAS ══════════════════════════════════

    // ── AR-01: crea área válida → 201 ─────────────────────────────────────

    public function testAR01CreaAreaValidaEs201(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/areas', ['nombre' => 'Nueva Área']);

        $r->assertStatus(201);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('Nueva Área', $data['nombre']);
        $this->assertTrue($data['activa']);
        $this->assertArrayHasKey('id', $data);

        $aud = Database::connect()->table('auditoria')->where('accion', 'alta_area')->where('entidad_id', (int) $data['id'])->countAllResults();
        $this->assertSame(1, $aud);
    }

    // ── AR-02: nombre duplicado → 422 ─────────────────────────────────────

    public function testAR02NombreDuplicadoEs422(): void
    {
        // 'Coordinación operativa' ya existe (área 1 del seed).
        $r = $this->como('direccion@demo.test')->post('api/v1/areas', ['nombre' => 'Coordinación operativa']);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('nombre', $cuerpo['campos']);
    }

    public function testCrearAreaSinNombreEs422(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/areas', ['nombre' => '   ']);
        $r->assertStatus(422);
        $this->assertArrayHasKey('nombre', $this->cuerpo($r)['campos']);
    }

    // ── AR-03: edita área (nombre/activa) → 200 ───────────────────────────

    public function testAR03EditaNombreEs200(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/areas/1', ['nombre' => 'Operaciones']);

        $r->assertStatus(200);
        $this->assertSame('Operaciones', $this->cuerpo($r)['data']['nombre']);

        $aud = Database::connect()->table('auditoria')->where('accion', 'editar_area')->where('entidad_id', 1)->countAllResults();
        $this->assertSame(1, $aud);
    }

    public function testAR03DesactivaAreaEs200YDesapareceDelListado(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/areas/2', ['activa' => false]);
        $r->assertStatus(200);
        $this->assertFalse($this->cuerpo($r)['data']['activa']);

        // GET /areas solo lista activas → el 2 ya no aparece.
        $lista = $this->como('direccion@demo.test')->get('api/v1/areas');
        $ids = array_map(static fn (array $a) => $a['id'], $this->cuerpo($lista)['data']);
        $this->assertNotContains(2, $ids);
    }

    public function testEditarAreaNombreDuplicadoEs422(): void
    {
        // Poner en el área 2 el nombre del área 1.
        $r = $this->como('direccion@demo.test')->patch('api/v1/areas/2', ['nombre' => 'Coordinación operativa']);
        $r->assertStatus(422);
        $this->assertArrayHasKey('nombre', $this->cuerpo($r)['campos']);
    }

    public function testEditarAreaInexistenteEs404(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/areas/9999', ['nombre' => 'X']);
        $r->assertStatus(404);
    }

    // ── AR-04: rol no-Dirección hace POST/PATCH /areas → 403 ──────────────

    public function testAR04CrearAreaNoDireccionEs403(): void
    {
        $r = $this->como('coordinacion.operativa@demo.test')->post('api/v1/areas', ['nombre' => 'Intento']);
        $r->assertStatus(403);
        $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);
    }

    public function testAR04EditarAreaNoDireccionEs403(): void
    {
        $r = $this->como('responsable.uno@demo.test')->patch('api/v1/areas/1', ['nombre' => 'Intento']);
        $r->assertStatus(403);
    }
}
