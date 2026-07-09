<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Tests\Support\FakeTokenVerifier;

/**
 * Endpoints de LECTURA (Tarea 5 / S1.4): GET /me, GET /acuerdos, GET /acuerdos/{id},
 * GET /usuarios, GET /areas. Visibilidad server-side por rol (doc 04 §A01),
 * vencido derivado en lectura (RF-05.2) y recordatorios programados (RF-08).
 *
 * Los IDs de caso entre paréntesis referencian el doc 06 (plan de pruebas) y
 * el brief de la Tarea 5 (AU-0x, PA-0x).
 *
 * @group database
 *
 * @internal
 */
final class AcuerdosLecturaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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

        return $this;
    }

    private function cuerpo(TestResponse $r): array
    {
        return json_decode($r->response()->getJSON(), true);
    }

    // --- /me -------------------------------------------------------------

    public function testMeFormaExactaConConfigDeRecordatoriosDelSeed(): void
    {
        $r = $this->como('coordinacion.operativa@demo.test')->get('api/v1/me');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $this->assertSame([
            'usuario' => [
                'id' => 2, 'nombre' => 'Carla Coordinadora', 'email' => 'coordinacion.operativa@demo.test',
                'rol' => 'coordinador', 'area_id' => 1, 'activo' => true,
            ],
            'config_recordatorios' => [
                'dias_antes' => [7, 3, 1],
                'dia_compromiso' => true,
                'vencido_cada_dias' => 3,
                'vencido_max_repeticiones' => 5,
                'resumen_frecuencia' => 'semanal',
            ],
        ], $cuerpo);

        // Sin envoltura `data` (doc 05 §2.1) — verificado por la comparación exacta arriba.
        $this->assertArrayNotHasKey('data', $cuerpo);
    }

    // --- AU-01: responsable ve solo lo suyo -------------------------------

    public function testAU01ResponsableListaSoloAcuerdosDondeEsResponsableOCorresponsable(): void
    {
        // Rafael (id 5): responsable de 4 y 10; corresponsable de 3. No participa en 1,2,5,6,7,8,9.
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/acuerdos?per_page=200');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $ids    = array_map(static fn (array $a) => $a['id'], $cuerpo['data']);

        sort($ids);
        $this->assertSame([3, 4, 10], $ids);
        $this->assertSame(3, $cuerpo['meta']['total']);
    }

    // --- AU-02: detalle ajeno → 404 --------------------------------------

    public function testAU02ResponsablePideAcuerdoAjenoPorIdDevuelve404(): void
    {
        // Acuerdo 6 (área 2, responsable 3): Rafael (id 5) no participa ni es de esa área.
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/acuerdos/6');

        $r->assertStatus(404);
        $this->assertSame([
            'error'   => 'no_encontrado',
            'mensaje' => 'El acuerdo no existe o no es visible para tu cuenta.',
        ], $this->cuerpo($r));
    }

    public function testAU02InexistenteDevuelve404IgualQueAjeno(): void
    {
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/acuerdos/9999');

        $r->assertStatus(404);
    }

    // --- AU-03: coordinador ve su área + participaciones ------------------

    public function testAU03CoordinadorListaSuAreaMasParticipacionesSinVerOtrasAreas(): void
    {
        // Carla (id 2, área 1): ve acuerdos de área 1 (1,2,3,4,8,9,10) + donde participa
        // fuera de su área (ninguno adicional en el seed). NO ve acuerdos del área 2 (5,6,7)
        // salvo que participe — no participa en ninguno de esos.
        $r = $this->como('coordinacion.operativa@demo.test')->get('api/v1/acuerdos?per_page=200&estado=todos_abiertos');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $ids    = array_map(static fn (array $a) => $a['id'], $cuerpo['data']);
        sort($ids);

        // Área 1 abiertos: 3,4,8,9,10 (1 y 2 son concluidos, excluidos por default).
        $this->assertSame([3, 4, 8, 9, 10], $ids);
        foreach ($cuerpo['data'] as $a) {
            $this->assertSame(1, $a['area']['id'], "acuerdo {$a['id']} no debería ser visible (otra área)");
        }
    }

    public function testAU03CoordinadorVeParticipacionFueraDeSuAreaAunqueNoSeaDeElla(): void
    {
        // Camilo (id 3, área 2) es responsable del acuerdo 6 (su área) y del 2 (área 1,
        // concluido). Probamos que con estado=concluido ve el 2 aunque sea de otra área
        // porque es su responsable directo.
        $r = $this->como('coordinacion.vinculacion@demo.test')->get('api/v1/acuerdos?estado=concluido');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $ids    = array_map(static fn (array $a) => $a['id'], $cuerpo['data']);
        sort($ids);

        // Concluidos del seed: 1 (área 1, resp 2) y 2 (área 1, resp 3=Camilo).
        // Camilo ve el 2 (es su responsable) pero NO el 1 (ni de su área, ni participante).
        $this->assertSame([2], $ids);
    }

    // --- PA-01: default oculta concluidos ---------------------------------

    public function testPA01DefaultOcultaConcluidos(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        foreach ($cuerpo['data'] as $a) {
            $this->assertNotSame('concluido', $a['estado']);
        }
        // Seed: 10 acuerdos, 2 concluidos (1,2) → 8 abiertos visibles para dirección.
        $this->assertSame(8, $cuerpo['meta']['total']);
    }

    // --- PA-02: estado=concluido los muestra -------------------------------

    public function testPA02EstadoConcluidoMuestraSoloEsos(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos?estado=concluido');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $ids    = array_map(static fn (array $a) => $a['id'], $cuerpo['data']);
        sort($ids);

        $this->assertSame([1, 2], $ids);
        foreach ($cuerpo['data'] as $a) {
            $this->assertSame('concluido', $a['estado']);
        }
    }

    // --- PA-03: búsqueda q en tema, acción y nombre del responsable --------

    public function testPA03BusquedaQMatcheaEnTemaAccionYResponsable(): void
    {
        $direccion = $this->como('direccion@demo.test');

        // 1) por tema: acuerdo 4 tiene tema "Tablero de metas".
        $porTema = $this->cuerpo($direccion->get('api/v1/acuerdos?q=Tablero+de+metas&per_page=200'));
        $this->assertContains(4, array_map(static fn (array $a) => $a['id'], $porTema['data']));

        // 2) por acción: acuerdo 8 (revisar contenido exacto vía el seed).
        $acuerdoOcho = Database::connect()->table('acuerdos')->where('id', 8)->get()->getRowArray();
        $fragmentoAccion = mb_substr($acuerdoOcho['accion'], 0, 15);
        $porAccion = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos?q=' . rawurlencode($fragmentoAccion) . '&per_page=200'));
        $this->assertContains(8, array_map(static fn (array $a) => $a['id'], $porAccion['data']));

        // 3) por nombre del responsable: "Rafael" es responsable de 4 y 10.
        $porResponsable = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos?q=Rafael&per_page=200'));
        $ids = array_map(static fn (array $a) => $a['id'], $porResponsable['data']);
        sort($ids);
        $this->assertSame([4, 10], $ids);
    }

    // --- PA-04: dirección lista todo ---------------------------------------

    public function testPA04DireccionListaTodoYMetaTotalCoincideConAbiertosDelSeed(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos?per_page=200');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        // 10 acuerdos en el seed, 2 concluidos → 8 abiertos (en_proceso + vencido derivado).
        $this->assertSame(8, $cuerpo['meta']['total']);
        $this->assertCount(8, $cuerpo['data']);
    }

    // --- PA-05b: vencido derivado en lectura, sin escritura -----------------

    public function testPA05bAcuerdoEnProcesoConFechaPasadaSeDevuelveYFiltraComoVencidoSinTocarLaFila(): void
    {
        $db  = Database::connect();
        $hoy = Time::now()->toDateString();

        // Insertamos un acuerdo controlado en_proceso con fecha_compromiso AYER,
        // en vez de tocar db.json (intocable). Reunión/área/usuarios del seed.
        $ayer = Time::now()->subDays(1)->toDateString();
        $db->table('acuerdos')->insert([
            'reunion_id'       => 1,
            'area_id'          => 1,
            'tema'             => 'Prueba vencido derivado',
            'accion'           => 'Acuerdo de prueba PA-05b',
            'responsable_id'   => 4,
            'capturado_por_id' => 1,
            'fecha_compromiso' => $ayer,
            'estado'           => 'en_proceso', // crudo en BD — NUNCA se escribe 'vencido' aquí.
            'created_at'       => Time::now()->toDateTimeString(),
        ]);
        $nuevoId = (int) $db->insertID();

        // 1) el detalle lo muestra como vencido.
        $detalle = $this->cuerpo($this->como('direccion@demo.test')->get("api/v1/acuerdos/{$nuevoId}"));
        $this->assertSame('vencido', $detalle['data']['estado']);

        // 2) el listado con estado=vencido lo incluye.
        $listaVencidos = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos?estado=vencido&per_page=200'));
        $this->assertContains($nuevoId, array_map(static fn (array $a) => $a['id'], $listaVencidos['data']));

        // 3) el listado con estado=en_proceso NO lo incluye (fue reclasificado).
        $listaEnProceso = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos?estado=en_proceso&per_page=200'));
        $this->assertNotContains($nuevoId, array_map(static fn (array $a) => $a['id'], $listaEnProceso['data']));

        // 4) la fila en BD sigue con estado='en_proceso' crudo (no se escribió nada).
        $filaCruda = $db->table('acuerdos')->where('id', $nuevoId)->get()->getRowArray();
        $this->assertSame('en_proceso', $filaCruda['estado']);
        $this->assertLessThan($hoy, $filaCruda['fecha_compromiso']);
    }

    // --- Detalle de acuerdo 4 (corresponsables + override) ------------------

    public function testDetalleAcuerdo4CorresponsablesOverrideYRecordatoriosCoherentes(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos/4');

        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];

        $this->assertSame(4, $data['id']);
        $this->assertSame([5, 1], $data['recordatorio_dias']);

        $corresponsablesIds = array_map(static fn (array $c) => $c['id'], $data['corresponsables']);
        sort($corresponsablesIds);
        $this->assertSame([4, 6], $corresponsablesIds);

        // Recordatorios programados coherentes con el override [5,1] sobre fecha_compromiso 2026-07-09:
        // previo -5d = 2026-07-04 (ya enviado en el seed), previo -1d = 2026-07-08, dia = 2026-07-09.
        $porTipo = [];
        foreach ($data['recordatorios'] as $rec) {
            $porTipo[$rec['tipo'] . '|' . $rec['programado_para']] = $rec['estado'];
        }
        $this->assertSame('enviado', $porTipo['previo|2026-07-04'] ?? null);
        $this->assertSame('programado', $porTipo['previo|2026-07-08'] ?? null);
        $this->assertSame('programado', $porTipo['dia|2026-07-09'] ?? null);
        $this->assertCount(3, $data['recordatorios']); // en_proceso: sin bloque de "vencido".

        // avances: 1 avance del seed, con usuario ref.
        $this->assertCount(1, $data['avances']);
        $this->assertSame('Rafael Responsable', $data['avances'][0]['usuario']['nombre']);
    }

    public function testDetalleIncluyeAvancesEnOrdenDescendentePorFecha(): void
    {
        // Acuerdo 3 tiene 1 avance en el seed; agregamos uno más reciente para
        // confirmar el orden created_at DESC.
        $db = Database::connect();
        $db->table('avances')->insert([
            'acuerdo_id'  => 3,
            'usuario_id'  => 4,
            'tipo'        => 'avance',
            'descripcion' => 'Avance más reciente de prueba',
            'created_at'  => '2026-07-08 08:00:00',
        ]);

        $r     = $this->como('direccion@demo.test')->get('api/v1/acuerdos/3');
        $data  = $this->cuerpo($r)['data'];
        $fechas = array_map(static fn (array $a) => $a['created_at'], $data['avances']);

        $this->assertSame($fechas, array_reverse((static function () use ($fechas) {
            $ordenado = $fechas;
            sort($ordenado);

            return $ordenado;
        })()));
    }

    // --- Filtros adicionales: responsable_id, desde/hasta, per_page ----------

    public function testFiltroResponsableIdRestringeElListado(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos?responsable_id=5&per_page=200');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $ids    = array_map(static fn (array $a) => $a['id'], $cuerpo['data']);
        sort($ids);

        // Rafael (id 5) es responsable directo de 4 y 10 (3 es corresponsable, no responsable).
        $this->assertSame([4, 10], $ids);
    }

    public function testFiltroDesdeHastaRestringeElRangoDeFechaCompromiso(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos?desde=2026-07-11&hasta=2026-07-16&estado=todos_abiertos&per_page=200');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $ids    = array_map(static fn (array $a) => $a['id'], $cuerpo['data']);
        sort($ids);

        // Abiertos con fecha_compromiso en [07-11, 07-16]: 5 (07-11), 6 (07-14), 7 (07-16).
        $this->assertSame([5, 6, 7], $ids);
    }

    public function testPerPageSeTopaEn200AunSiSePideMas(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos?per_page=9999');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame(200, $cuerpo['meta']['per_page']);
    }

    public function testPerPagePorDefectoEs50(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos');

        $r->assertStatus(200);
        $this->assertSame(50, $this->cuerpo($r)['meta']['per_page']);
    }

    public function testOrdenEsPorFechaCompromisoAscendenteLuegoId(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/acuerdos?estado=todos_abiertos&per_page=200');

        $r->assertStatus(200);
        $fechas = array_map(static fn (array $a) => $a['fecha_compromiso'], $this->cuerpo($r)['data']);
        $ordenadas = $fechas;
        sort($ordenadas);

        $this->assertSame($ordenadas, $fechas);
    }

    // --- GET /usuarios y /areas: visibles para cualquier autenticado --------

    public function testUsuariosDevuelveSoloActivosParaCualquierRol(): void
    {
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/usuarios');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $ids    = array_map(static fn (array $u) => $u['id'], $cuerpo['data']);

        $this->assertNotContains(7, $ids); // Bruno Baja, inactivo.
        $this->assertCount(6, $cuerpo['data']);
        foreach ($cuerpo['data'] as $u) {
            $this->assertTrue($u['activo']);
        }
    }

    public function testAreasDevuelveCatalogoActivoParaCualquierRol(): void
    {
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/areas');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $this->assertSame([
            ['id' => 1, 'nombre' => 'Coordinación operativa', 'activa' => true],
            ['id' => 2, 'nombre' => 'Participación y vinculación', 'activa' => true],
        ], $cuerpo['data']);
    }

    // --- Cero N+1: nº de queries del listado no crece con las filas ---------

    public function testListadoDireccionEjecutaUnNumeroConstanteDeQueriesIndependienteDeLasFilas(): void
    {
        // Warm-up: la primera request de la sesión resuelve el actor con una query extra
        // (aún no hay entrada en el cache de auth, TTL 60s). Medimos en estado ESTABLE
        // (cache caliente) en ambos lados de la comparación para que el delta refleje
        // solo el trabajo del propio listado, no el coste incidental de auth.
        $this->como('direccion@demo.test')->get('api/v1/acuerdos?per_page=200');

        $queriesConSeedOriginal = $this->contarQueriesDeLaRequest(
            fn () => $this->como('direccion@demo.test')->get('api/v1/acuerdos?per_page=200'),
        );

        // Multiplicamos las filas visibles insertando acuerdos adicionales (mismo patrón que el seed),
        // cada uno CON corresponsable — si hubiera N+1 al resolver joins o corresponsables, el nº de
        // queries crecería con las filas.
        $db = Database::connect();
        for ($i = 0; $i < 20; $i++) {
            $db->table('acuerdos')->insert([
                'reunion_id'       => 1,
                'area_id'          => 1,
                'tema'             => "Carga N+1 #{$i}",
                'accion'           => "Acuerdo de prueba de carga #{$i}",
                'responsable_id'   => 4,
                'capturado_por_id' => 1,
                'fecha_compromiso' => Time::now()->addDays($i + 1)->toDateString(),
                'estado'           => 'en_proceso',
                'created_at'       => Time::now()->toDateTimeString(),
            ]);
            $nuevoId = (int) $db->insertID();
            $db->table('acuerdo_corresponsables')->insert(['acuerdo_id' => $nuevoId, 'usuario_id' => 6]);
        }

        $queriesConMasFilas = $this->contarQueriesDeLaRequest(
            fn () => $this->como('direccion@demo.test')->get('api/v1/acuerdos?per_page=200'),
        );

        $this->assertSame(
            $queriesConSeedOriginal,
            $queriesConMasFilas,
            'El nº de queries del listado debe ser constante independientemente del nº de filas (cero N+1).',
        );
        // Documenta el nº exacto esperado dentro del controller: 1 (SELECT con joins+visibilidad+
        // filtros+paginación) + 1 (COUNT total, mismo builder) + 1 (whereIn corresponsables de la
        // página) = 3. (La resolución del actor por FirebaseAuthFilter puede sumar 0-1 queries
        // adicionales cacheadas 60s — por eso comparamos el DELTA, no un valor absoluto ajeno al SUT.)
        $this->assertSame(3, $queriesConSeedOriginal);

        // Tercer punto de dato a escala mayor (doc brief S3.1: "20 y luego 200"): llevamos el
        // total de filas visibles a ~200 (10 del seed + 20 ya insertadas + 170 más, todas CON
        // corresponsable) y confirmamos que el nº de queries de la página sigue siendo el mismo.
        for ($i = 20; $i < 190; $i++) {
            $db->table('acuerdos')->insert([
                'reunion_id'       => 1,
                'area_id'          => 1,
                'tema'             => "Carga N+1 #{$i}",
                'accion'           => "Acuerdo de prueba de carga #{$i}",
                'responsable_id'   => 4,
                'capturado_por_id' => 1,
                'fecha_compromiso' => Time::now()->addDays($i + 1)->toDateString(),
                'estado'           => 'en_proceso',
                'created_at'       => Time::now()->toDateTimeString(),
            ]);
            $nuevoId = (int) $db->insertID();
            $db->table('acuerdo_corresponsables')->insert(['acuerdo_id' => $nuevoId, 'usuario_id' => 6]);
        }

        $queriesConDoscientasFilas = $this->contarQueriesDeLaRequest(
            fn () => $this->como('direccion@demo.test')->get('api/v1/acuerdos?per_page=200'),
        );

        $this->assertSame(
            $queriesConSeedOriginal,
            $queriesConDoscientasFilas,
            'A ~200 filas visibles el nº de queries del listado sigue siendo constante (cero N+1 a escala).',
        );
    }

    // --- Cero N+1: nº de queries del detalle no crece con el nº de avances ---

    public function testDetalleEjecutaUnNumeroConstanteDeQueriesIndependienteDelNumeroDeAvances(): void
    {
        // Acuerdo 4 (área 1, dirección lo ve): ya tiene 1 avance + corresponsables + override
        // de recordatorio_dias en el seed (ver testDetalleAcuerdo4...). Warm-up: cache de auth caliente.
        $this->como('direccion@demo.test')->get('api/v1/acuerdos/4');

        $queriesConSeedOriginal = $this->contarQueriesDeLaRequest(
            fn () => $this->como('direccion@demo.test')->get('api/v1/acuerdos/4'),
        );

        // Multiplicamos los avances del acuerdo 4 — si `hidratarAvances()` resolviera el usuario
        // avance por avance (en vez de un whereIn agrupado), el nº de queries crecería con ellos.
        $db = Database::connect();
        for ($i = 0; $i < 50; $i++) {
            $db->table('avances')->insert([
                'acuerdo_id'  => 4,
                'usuario_id'  => $i % 2 === 0 ? 4 : 6, // alterna responsable/corresponsable ya sembrados.
                'tipo'        => 'avance',
                'descripcion' => "Avance de carga N+1 #{$i}",
                'created_at'  => Time::now()->subMinutes($i)->toDateTimeString(),
            ]);
        }

        $queriesConMasAvances = $this->contarQueriesDeLaRequest(
            fn () => $this->como('direccion@demo.test')->get('api/v1/acuerdos/4'),
        );

        $this->assertSame(
            $queriesConSeedOriginal,
            $queriesConMasAvances,
            'El nº de queries del detalle debe ser constante independientemente del nº de avances (cero N+1).',
        );
        // Documenta el nº exacto esperado dentro del controller `show()`: 1 (builderConJoins del
        // acuerdo) + 1 (count de acuerdo_corresponsables para esCorresponsable) + 1
        // (cargarCorresponsables, whereIn) + 1 (avances findAll) + 1 (hidratarAvances, whereIn de
        // usuarios) + 1 (config global de recordatorios) + 1 (recordatorios enviados) = 7.
        $this->assertSame(7, $queriesConSeedOriginal);

        // Verificación funcional: el endpoint sí refleja los 51 avances (1 seed + 50 nuevos).
        $detalle = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos/4'));
        $this->assertCount(51, $detalle['data']['avances']);
    }

    /** Cuenta TODAS las queries SQL disparadas durante la ejecución de `$accion`, vía el evento `DBQuery`. */
    private function contarQueriesDeLaRequest(callable $accion): int
    {
        $contador = 0;
        Events::on('DBQuery', static function (Query $query) use (&$contador): void {
            $contador++;
        });

        $accion();

        Events::removeAllListeners('DBQuery');

        return $contador;
    }
}
