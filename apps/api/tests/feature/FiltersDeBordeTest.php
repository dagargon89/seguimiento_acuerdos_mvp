<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use App\Libraries\Auth\AuthCache;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\FakeTokenVerifier;

/**
 * Filters de borde del backend (S1.3): FirebaseAuthFilter, ThrottleFilter, CORS,
 * SecurityHeadersFilter. Usa el grupo protegido real (api/v1 + _auth_smoke,
 * TODO(tarea-5): retirar) y un FakeTokenVerifier inyectado vía
 * Services::injectMock — sin red ni tokens reales de Firebase.
 *
 * Los IDs de caso entre paréntesis referencian el doc 06 (plan de pruebas).
 *
 * @group database
 *
 * @internal
 */
final class FiltersDeBordeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

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

    // --- AU-08: sin header / token rechazado por el verifier -----------------

    public function testAU08aSinHeaderAuthorizationDevuelve401TokenFaltante(): void
    {
        $resultado = $this->get('api/v1/_auth_smoke');

        $resultado->assertStatus(401);
        $resultado->assertJSONExact(['error' => 'token_faltante', 'mensaje' => 'Falta el encabezado Authorization: Bearer <token>.']);
    }

    public function testAU08bTokenExpiradoDevuelve401TokenInvalido(): void
    {
        $this->fake->rechaza('expirado');

        $resultado = $this->withHeaders(['Authorization' => 'Bearer token-expirado'])->get('api/v1/_auth_smoke');

        $resultado->assertStatus(401);
        $resultado->assertJSONExact(['error' => 'token_invalido', 'mensaje' => 'El token de sesión no es válido o expiró.']);
    }

    public function testAU08cFirmaInvalidaDevuelve401(): void
    {
        $this->fake->rechaza('firma');

        $resultado = $this->withHeaders(['Authorization' => 'Bearer token-firma-mala'])->get('api/v1/_auth_smoke');

        $resultado->assertStatus(401);
        $resultado->assertJSONExact(['error' => 'token_invalido', 'mensaje' => 'El token de sesión no es válido o expiró.']);
    }

    // --- OW-07: token de otro proyecto (aud) ---------------------------------

    public function testOW07AudienciaDeOtroProyectoDevuelve401(): void
    {
        $this->fake->rechaza('aud');

        $resultado = $this->withHeaders(['Authorization' => 'Bearer token-otro-proyecto'])->get('api/v1/_auth_smoke');

        $resultado->assertStatus(401);
        $resultado->assertJSONExact(['error' => 'token_invalido', 'mensaje' => 'El token de sesión no es válido o expiró.']);
    }

    // --- AU-09: email no registrado / usuario inactivo -----------------------

    public function testAU09TokenValidoDeEmailNoRegistradoDevuelve403UsuarioNoRegistrado(): void
    {
        $this->fake->exito('fb-uid-desconocido', 'nadie@demo.test', true);

        $resultado = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');

        $resultado->assertStatus(403);
        $resultado->assertJSONExact(['error' => 'usuario_no_registrado', 'mensaje' => 'Esta cuenta no tiene acceso al panel.']);
    }

    public function testAU09bUsuarioExistenteInactivoDevuelve403(): void
    {
        // Usuario id=7 (Bruno Baja) en db.json: activo=false, firebase_uid=null.
        // Le damos un firebase_uid vía el fake para simular que sí llegó a
        // tener sesión de Firebase alguna vez (login previo a su baja).
        Database::connect()->table('usuarios')->where('id', 7)->update(['firebase_uid' => 'fb-demo-baja-001']);

        $this->fake->exito('fb-demo-baja-001', 'persona.baja@demo.test', true);

        $resultado = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');

        $resultado->assertStatus(403);
        $resultado->assertJSONExact(['error' => 'usuario_no_registrado', 'mensaje' => 'Esta cuenta no tiene acceso al panel.']);
    }

    // --- RF-01.3: primer login enlaza firebase_uid ---------------------------

    public function testRF013PrimerLoginEnlazaFirebaseUidPorEmail(): void
    {
        // Usuario id=4 (Rita Responsable) ya tiene firebase_uid en el seed;
        // simulamos que aún no ha hecho login (caso real de alta nueva)
        // limpiando el uid y presentando uno nuevo con el mismo email verificado.
        Database::connect()->table('usuarios')->where('id', 4)->update(['firebase_uid' => null]);

        $this->fake->exito('fb-uid-nuevo-primer-login', 'responsable.uno@demo.test', true);

        $resultado = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');

        $resultado->assertStatus(200);
        $resultado->assertJSON();
        $cuerpo = json_decode($resultado->response()->getJSON(), true);
        $this->assertSame(4, (int) $cuerpo['data']['usuario']['id']);

        $fila = Database::connect()->table('usuarios')->where('id', 4)->get()->getRowArray();
        $this->assertSame('fb-uid-nuevo-primer-login', $fila['firebase_uid']);

        // Auditoría del primer login (doc 04 §A09).
        $auditoria = Database::connect()->table('auditoria')
            ->where('usuario_id', 4)->where('accion', 'login')->get()->getRowArray();
        $this->assertNotNull($auditoria);
    }

    public function testRF013SegundoLoginNoEnlazaOtroEmailAUnUsuarioYaEnlazado(): void
    {
        // Usuario id=4 ya tiene firebase_uid en el seed (login normal, no primero).
        $this->fake->exito('fb-demo-resp-001', 'responsable.uno@demo.test', true);

        $resultado = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');

        $resultado->assertStatus(200);
    }

    // --- AU-10: desactivación efectiva tras invalidar cache ------------------

    public function testAU10UsuarioDesactivadoTrasInvalidarCacheDevuelve403(): void
    {
        $this->fake->exito('fb-demo-resp-001', 'responsable.uno@demo.test', true);

        // Primera request: usuario activo, 200, y queda cacheado 60s.
        $primera = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');
        $primera->assertStatus(200);

        // Dirección desactiva al usuario e invalida su cache (lo que hará el
        // endpoint PATCH /usuarios/{id} de una tarea posterior).
        Database::connect()->table('usuarios')->where('id', 4)->update(['activo' => 0]);
        AuthCache::invalidar(4);

        $segunda = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');
        $segunda->assertStatus(403);
        $segunda->assertJSONExact(['error' => 'usuario_no_registrado', 'mensaje' => 'Esta cuenta no tiene acceso al panel.']);
    }

    public function testAU10SinInvalidarCacheElUsuarioDesactivadoSigueEntrandoDentroDelTtl(): void
    {
        // Documenta el comportamiento aceptado por RF-01 ("≤60s"): sin invalidar
        // el cache explícitamente, la baja tarda hasta el TTL en tener efecto.
        $this->fake->exito('fb-demo-resp-001', 'responsable.uno@demo.test', true);

        $primera = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');
        $primera->assertStatus(200);

        Database::connect()->table('usuarios')->where('id', 4)->update(['activo' => 0]);

        $segunda = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');
        $segunda->assertStatus(200);
    }

    // --- OW-05: throttle -------------------------------------------------------

    public function testOW05LimiteYaConsumidoDevuelve429ConRetryAfter(): void
    {
        $this->fake->exito('fb-demo-resp-001', 'responsable.uno@demo.test', true);

        // Agotamos el bucket de 60 req/min del usuario id=4 directamente en cache,
        // sin hacer 61 requests reales.
        $cache = service('cache');
        $cache->save('throttler_rl.usuario.4', 0, 60);
        $cache->save('throttler_rl.usuario.4Time', time(), 60);

        $resultado = $this->withHeaders(['Authorization' => 'Bearer token-valido'])->get('api/v1/_auth_smoke');

        $resultado->assertStatus(429);
        $resultado->assertJSONExact(['error' => 'rate_limit', 'mensaje' => 'Demasiadas solicitudes. Intenta de nuevo más tarde.']);
        $this->assertNotSame('', $resultado->response()->getHeaderLine('Retry-After'));
        $this->assertGreaterThanOrEqual(1, (int) $resultado->response()->getHeaderLine('Retry-After'));
    }

    // --- OW-04: CORS preflight --------------------------------------------------

    public function testOW04PreflightDeOrigenNoListadoSinHeadersCors(): void
    {
        $resultado = $this->withHeaders([
            'Origin'                        => 'http://origen-no-permitido.example',
            'Access-Control-Request-Method' => 'GET',
        ])->options('api/v1/_auth_smoke');

        $resultado->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function testOW04PreflightDeOrigenListadoIncluyeHeadersCors(): void
    {
        $resultado = $this->withHeaders([
            'Origin'                        => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'GET',
        ])->options('api/v1/_auth_smoke');

        $resultado->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function testOW04PreflightNoPasaPorAuthNiDevuelve401(): void
    {
        // El preflight de un origen listado no debe requerir Authorization.
        $resultado = $this->withHeaders([
            'Origin'                        => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'GET',
        ])->options('api/v1/_auth_smoke');

        $this->assertNotSame(401, $resultado->response()->getStatusCode());
    }

    // --- Security headers (global, incluso en 401) ----------------------------

    public function testHeadersDeSeguridadPresentesInclusoEnRespuesta401(): void
    {
        $resultado = $this->get('api/v1/_auth_smoke');

        $resultado->assertStatus(401);
        $resultado->assertHeader('X-Content-Type-Options', 'nosniff');
        $resultado->assertHeader('X-Frame-Options', 'DENY');
        $resultado->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $resultado->assertHeader('Content-Security-Policy', "default-src 'none'");
        $resultado->assertHeaderMissing('Strict-Transport-Security');
    }
}
