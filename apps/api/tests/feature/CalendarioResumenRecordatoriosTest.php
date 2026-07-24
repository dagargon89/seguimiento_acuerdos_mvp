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
use Tests\Support\FechaFijaTrait;

/**
 * Calendario, resumen, recordatorios (lectura) y configuración de
 * recordatorios (Tarea 8 / S1.7): `GET /calendario`, `GET /resumen`,
 * `GET /recordatorios/proximos|historial`, `GET|PUT /configuracion/recordatorios`.
 *
 * IDs de caso (PA-05, RE-09) referencian el doc 06 (plan de pruebas) y el
 * brief de la Tarea 8.
 *
 * Seed (db.json, "hoy" = 2026-07-09):
 * - acuerdos: 1 (área1, resp2, concluido, 2026-06-26), 2 (área1, resp3, concluido, 2026-07-05),
 *   3 (área1, resp4, vencido, 2026-07-06, corresp 5), 4 (área1, resp5, en_proceso, 2026-07-09,
 *   corresp 4+6, override [5,1]), 5 (área2, resp6, en_proceso, 2026-07-11),
 *   6 (área2, resp3, en_proceso, 2026-07-14), 7 (área2, resp6, en_proceso, 2026-07-16, corresp 4),
 *   8 (área1, resp2, en_proceso, 2026-07-18), 9 (área1, resp4, en_proceso, 2026-07-22),
 *   10 (área1, resp5, vencido, 2026-07-07).
 *
 * @group database
 *
 * @internal
 */
final class CalendarioResumenRecordatoriosTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use FechaFijaTrait;

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

    // ── GET /calendario — PA-05: agrupación por día en TZ Juárez ────────────

    public function testPA05CalendarioAgrupaPorDiaYOcultaConcluidosPorDefecto(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/calendario?mes=2026-07');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $this->assertArrayNotHasKey('data', $cuerpo);
        $this->assertSame('2026-07', $cuerpo['mes']);

        $fechas = array_map(static fn (array $d) => $d['fecha'], $cuerpo['dias']);
        // Acuerdo 2 (concluido, 2026-07-05) excluido por default; el resto de julio
        // visible para dirección: 3,4,5,6,7,8,9,10.
        $this->assertNotContains('2026-07-05', $fechas);
        $this->assertSame($fechas, (static function () use ($fechas) {
            $o = $fechas;
            sort($o);

            return $o;
        })());

        $idsVisibles = [];
        foreach ($cuerpo['dias'] as $dia) {
            foreach ($dia['acuerdos'] as $a) {
                $idsVisibles[] = $a['id'];
                $this->assertSame($dia['fecha'], $a['fecha_compromiso']);
            }
        }
        sort($idsVisibles);
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $idsVisibles);
    }

    public function testCalendarioIncluirConcluidosMuestraTambienLosConcluidosDelMes(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/calendario?mes=2026-07&incluir_concluidos=true');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $idsVisibles = [];
        foreach ($cuerpo['dias'] as $dia) {
            foreach ($dia['acuerdos'] as $a) {
                $idsVisibles[] = $a['id'];
            }
        }
        sort($idsVisibles);
        // Ahora también el 2 (concluido, 2026-07-05); el 1 es de junio, fuera de rango.
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], $idsVisibles);
    }

    public function testCalendarioMesJunioSoloMuestraElConcluidoUnoConIncluirConcluidos(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/calendario?mes=2026-06&incluir_concluidos=true');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $idsVisibles = [];
        foreach ($cuerpo['dias'] as $dia) {
            foreach ($dia['acuerdos'] as $a) {
                $idsVisibles[] = $a['id'];
            }
        }
        $this->assertSame([1], $idsVisibles);
    }

    /**
     * Caso frontera de fin de mes/medianoche (regresión BQS): un acuerdo con
     * `fecha_compromiso` en el ÚLTIMO día del mes debe agruparse en ESE mes,
     * y uno en el PRIMER día del mes siguiente NO debe aparecer. Si el cálculo
     * usara medianoche UTC en vez de TZ America/Ciudad_Juarez, el rango
     * `[desde,hasta]` podría desplazarse un día y fallar esta prueba.
     */
    public function testCalendarioCasoFronteraFinDeMesNoSeCorreAOtroMes(): void
    {
        $db = Database::connect();
        $db->table('acuerdos')->insert([
            'reunion_id'       => 1,
            'area_id'          => 1,
            'accion'           => 'Frontera último día de julio',
            'responsable_id'   => 4,
            'capturado_por_id' => 1,
            'fecha_compromiso' => '2026-07-31',
            'estado'           => 'en_proceso',
            'created_at'       => Time::now()->toDateTimeString(),
        ]);
        $idJulio31 = (int) $db->insertID();

        $db->table('acuerdos')->insert([
            'reunion_id'       => 1,
            'area_id'          => 1,
            'accion'           => 'Frontera primer día de agosto',
            'responsable_id'   => 4,
            'capturado_por_id' => 1,
            'fecha_compromiso' => '2026-08-01',
            'estado'           => 'en_proceso',
            'created_at'       => Time::now()->toDateTimeString(),
        ]);
        $idAgosto1 = (int) $db->insertID();

        $julio = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/calendario?mes=2026-07'));
        $idsJulio = [];
        foreach ($julio['dias'] as $dia) {
            foreach ($dia['acuerdos'] as $a) {
                $idsJulio[] = $a['id'];
            }
        }
        $this->assertContains($idJulio31, $idsJulio);
        $this->assertNotContains($idAgosto1, $idsJulio);

        // Y el día '2026-07-31' existe como clave exacta en `dias`.
        $fechaEncontrada = null;
        foreach ($julio['dias'] as $dia) {
            if ($dia['fecha'] === '2026-07-31') {
                $fechaEncontrada = $dia;
            }
        }
        $this->assertNotNull($fechaEncontrada);

        $agosto = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/calendario?mes=2026-08'));
        $idsAgosto = [];
        foreach ($agosto['dias'] as $dia) {
            foreach ($dia['acuerdos'] as $a) {
                $idsAgosto[] = $a['id'];
            }
        }
        $this->assertContains($idAgosto1, $idsAgosto);
        $this->assertNotContains($idJulio31, $idsAgosto);
    }

    /**
     * ADR-007 (visibilidad abierta): el calendario deriva su visibilidad de
     * `VisibilidadAcuerdos::aplicarAlListado`, igual que `/acuerdos` — al
     * abrirse la política, un responsable ve TODOS los acuerdos del mes (no
     * solo donde participa). Antes de ADR-007 este test verificaba [3,4,10].
     */
    public function testCalendarioVisibilidadAbiertaResponsableVeTodosLosAcuerdosDelMesIgualQueAcuerdos(): void
    {
        // Rafael (id 5, responsable): antes de ADR-007 solo veía 3,4,10 (donde participa).
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/calendario?mes=2026-07&incluir_concluidos=true');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $ids = [];
        foreach ($cuerpo['dias'] as $dia) {
            foreach ($dia['acuerdos'] as $a) {
                $ids[] = $a['id'];
            }
        }
        sort($ids);
        // Todo julio con incluir_concluidos=true: 2,3,4,5,6,7,8,9,10 (1 es de junio).
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], $ids);
    }

    public function testCalendarioMesInvalidoDevuelve422(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/calendario?mes=julio-2026');

        $r->assertStatus(422);
        $this->assertSame('validacion', $this->cuerpo($r)['error']);
    }

    // ── GET /resumen — RF-11 ─────────────────────────────────────────────────

    public function testResumenDireccionVeAmbitoGeneralConTotalesCorrectos(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/resumen');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $this->assertArrayNotHasKey('data', $cuerpo);

        $this->assertSame('general', $cuerpo['ambito']);
        $this->assertNull($cuerpo['area']);

        // Abiertos (8): en_proceso = 4,5,6,7,8,9 (6) ; vencidos = 3,10 (2).
        $this->assertSame(6, $cuerpo['en_proceso']);
        $this->assertSame(2, $cuerpo['vencidos']);
        $this->assertSame(2, $cuerpo['concluidos']); // 1, 2

        // por_vencer_7d: en_proceso con fecha_compromiso en [hoy, hoy+7] = [2026-07-09, 2026-07-16].
        // Candidatos en_proceso: 4(07-09), 5(07-11), 6(07-14), 7(07-16), 8(07-18), 9(07-22).
        // Dentro del rango: 4,5,6,7 → 4.
        $this->assertSame(4, $cuerpo['por_vencer_7d']);
    }

    public function testResumenCoordinadorVeSoloSuAreaPorAreaIdNoPorVisibilidadDeParticipacion(): void
    {
        // Carla (id 2, área 1). Área 1: 1(concl),2(concl),3(venc),4(proc),8(proc),9(proc),10(venc).
        $r = $this->como('coordinacion.operativa@demo.test')->get('api/v1/resumen');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $this->assertSame('area', $cuerpo['ambito']);
        $this->assertSame(1, $cuerpo['area']['id']);

        $this->assertSame(3, $cuerpo['en_proceso']); // 4, 8, 9
        $this->assertSame(2, $cuerpo['vencidos']);   // 3, 10
        $this->assertSame(2, $cuerpo['concluidos']); // 1, 2

        $porResp = [];
        foreach ($cuerpo['por_responsable'] as $item) {
            $porResp[$item['responsable']['id']] = $item;
        }
        // Responsables del área 1 abiertos: 4→resp5, 3→resp4, 8→resp2, 9→resp4, 10→resp5.
        $this->assertSame(1, $porResp[5]['en_proceso']); // acuerdo 4
        $this->assertSame(1, $porResp[5]['vencidos']);   // acuerdo 10
        $this->assertSame(1, $porResp[4]['vencidos']);   // acuerdo 3
        $this->assertSame(1, $porResp[4]['en_proceso']); // acuerdo 9
        $this->assertSame(1, $porResp[2]['en_proceso']); // acuerdo 8
    }

    public function testResumenCoordinadorNoVeCifrasDeOtraArea(): void
    {
        // Camilo (id 3, área 2). Área 2: 5(proc),6(proc),7(proc). Sin vencidos ni concluidos.
        $r = $this->como('coordinacion.vinculacion@demo.test')->get('api/v1/resumen');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $this->assertSame(2, $cuerpo['area']['id']);
        $this->assertSame(3, $cuerpo['en_proceso']);
        $this->assertSame(0, $cuerpo['vencidos']);
        $this->assertSame(0, $cuerpo['concluidos']);
    }

    public function testResumenResponsableSegunElMockRecibe403(): void
    {
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/resumen');

        $r->assertStatus(403);
        $this->assertSame([
            'error'   => 'sin_permiso',
            'mensaje' => 'El resumen es para dirección y coordinaciones.',
        ], $this->cuerpo($r));
    }

    // ── GET /recordatorios/proximos — RF-08 ──────────────────────────────────

    /**
     * ADR-007 (visibilidad abierta): `proximos` deriva de
     * `VisibilidadAcuerdos::aplicarAlListado`, igual que `/acuerdos` — un
     * responsable ahora ve recordatorios de TODOS los acuerdos abiertos, no
     * solo donde participa. Antes de ADR-007 este test verificaba [3,4,10].
     */
    public function testRecordatoriosProximosVisibilidadAbiertaIncluyeTodosLosAcuerdosAbiertos(): void
    {
        // Rafael (id 5, responsable): antes de ADR-007 solo veía 3,4,10 (donde participa).
        // Concluidos (1,2) no generan recordatorios (independiente de la visibilidad).
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/recordatorios/proximos');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $this->assertArrayHasKey('data', $cuerpo);

        $acuerdoIds = array_values(array_unique(array_map(static fn (array $x) => $x['acuerdo_id'], $cuerpo['data'])));
        sort($acuerdoIds);
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $acuerdoIds);

        foreach ($cuerpo['data'] as $item) {
            $this->assertSame($item['destinatario']['id'], $item['destinatario']['id']); // forma presente
            $this->assertArrayHasKey('key', $item);
            $this->assertArrayHasKey('programado_para', $item);
            $this->assertArrayHasKey('tipo', $item);
            $this->assertFalse($item['enviado']);
            $this->assertNull($item['estado_envio']);
            $this->assertNull($item['error']);
            $this->assertGreaterThanOrEqual('2026-07-09', $item['programado_para']);
        }
    }

    /**
     * ADR-007: un coordinador ahora SÍ ve recordatorios de acuerdos de otra
     * área (visibilidad abierta) — antes de ADR-007 este test verificaba lo
     * contrario (Camilo, área 2, no veía el acuerdo 4 de área 1).
     */
    public function testRecordatoriosProximosVisibilidadAbiertaIncluyeAcuerdosDeOtraArea(): void
    {
        // Camilo (id 3, área 2): ADR-007 → sí ve recordatorios del acuerdo 4 (área 1, ajeno).
        $r = $this->como('coordinacion.vinculacion@demo.test')->get('api/v1/recordatorios/proximos');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $acuerdoIds = array_map(static fn (array $x) => $x['acuerdo_id'], $cuerpo['data']);
        $this->assertContains(4, $acuerdoIds);
    }

    public function testRecordatoriosProximosIncluyeADestinatariosResponsableYCorresponsables(): void
    {
        // Acuerdo 4: responsable 5, corresponsables 4 y 6. Dirección ve todo.
        $r = $this->como('direccion@demo.test')->get('api/v1/recordatorios/proximos');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $destinatariosAcuerdo4 = array_values(array_unique(array_map(
            static fn (array $x) => $x['destinatario']['id'],
            array_filter($cuerpo['data'], static fn (array $x) => $x['acuerdo_id'] === 4),
        )));
        sort($destinatariosAcuerdo4);
        $this->assertSame([4, 5, 6], $destinatariosAcuerdo4);
    }

    // ── GET /recordatorios/historial — RF-08 ─────────────────────────────────

    /**
     * ADR-007 (visibilidad abierta): `historial` deriva de
     * `VisibilidadAcuerdos::aplicarAlListado` — con la regla abierta Rafael
     * (responsable) ve el historial de TODOS los acuerdos, no solo donde
     * participa. El resultado observable sigue siendo [3,4,10] porque esos
     * son los ÚNICOS acuerdos con `recordatorios_enviados` en el seed (el
     * resto de acuerdos visibles simplemente no tiene filas de historial) —
     * no es una coincidencia con la regla previa, es que el seed no da más
     * datos para diferenciarlas. El "resumen" global (acuerdo_id null, id 9)
     * sigue sin verlo (rol responsable, regla de `historial()` que NO cambia).
     */
    public function testRecordatoriosHistorialVisibilidadAbiertaIncluyeTodosLosAcuerdosConEnviosDelSeed(): void
    {
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/recordatorios/historial');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $acuerdoIds = array_values(array_unique(array_filter(array_map(static fn (array $x) => $x['acuerdo_id'], $cuerpo['data']))));
        sort($acuerdoIds);
        $this->assertSame([3, 4, 10], $acuerdoIds);
        $this->assertNotContains(null, array_map(static fn (array $x) => $x['acuerdo_id'], $cuerpo['data']));
    }

    public function testRecordatoriosHistorialDireccionVeElResumenPeriodicoGlobal(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/recordatorios/historial');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $conAcuerdoNull = array_filter($cuerpo['data'], static fn (array $x) => $x['acuerdo_id'] === null);
        $this->assertNotEmpty($conAcuerdoNull);
        $item = array_values($conAcuerdoNull)[0];
        $this->assertSame('resumen', $item['tipo']);
        $this->assertSame('Resumen periódico de pendientes', $item['accion']);
        $this->assertTrue($item['enviado']);
    }

    public function testRecordatoriosHistorialResumenSoloMuestraElPropioDelActorNoElDeOtroUsuario(): void
    {
        // Inserta un resumen propio para el coordinador operativo (id 2, distinto
        // del de Dirección que ya trae el seed con id 9, usuario 1) y otro para
        // vinculación (id 3), en una fecha nueva para no chocar con el seed.
        Database::connect()->table('recordatorios_enviados')->insertBatch([
            [
                'acuerdo_id'       => null,
                'usuario_id'       => 2,
                'tipo'             => 'resumen',
                'programado_para'  => '2026-07-13',
                'enviado_at'       => '2026-07-13 09:00:00',
                'estado'           => 'enviado',
                'gmail_message_id' => 'msg-test-resumen-coord-op',
                'error'            => null,
            ],
            [
                'acuerdo_id'       => null,
                'usuario_id'       => 3,
                'tipo'             => 'resumen',
                'programado_para'  => '2026-07-13',
                'enviado_at'       => '2026-07-13 09:00:00',
                'estado'           => 'enviado',
                'gmail_message_id' => 'msg-test-resumen-coord-vinc',
                'error'            => null,
            ],
        ]);

        // Vinculación (id 3) no debe ver el resumen del coordinador operativo (id 2).
        $r = $this->como('coordinacion.vinculacion@demo.test')->get('api/v1/recordatorios/historial');
        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $resumenes = array_values(array_filter($cuerpo['data'], static fn (array $x) => $x['tipo'] === 'resumen'));
        $this->assertNotEmpty($resumenes, 'sí debe ver su propio resumen');
        foreach ($resumenes as $item) {
            $this->assertSame(3, $item['destinatario']['id'], 'no debe ver resumen de otro usuario (id 2 o 1)');
        }

        // Y el resumen propio (usuario 3, 2026-07-13) sí aparece.
        $propios = array_values(array_filter(
            $resumenes,
            static fn (array $x) => $x['programado_para'] === '2026-07-13',
        ));
        $this->assertNotEmpty($propios, 'debe ver su propio resumen del 2026-07-13');
    }

    public function testRecordatoriosHistorialFormaCorrectaIncluyeFallidos(): void
    {
        $r = $this->como('direccion@demo.test')->get('api/v1/recordatorios/historial');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);

        $fallidos = array_values(array_filter($cuerpo['data'], static fn (array $x) => $x['estado_envio'] === 'fallido'));
        $this->assertNotEmpty($fallidos);
        $this->assertFalse($fallidos[0]['enviado']);
        $this->assertNotNull($fallidos[0]['error']);
    }

    // ── GET/PUT /configuracion/recordatorios — RF-08 ─────────────────────────

    public function testGetConfigDevuelveLaGlobalDelSeedParaCualquierRol(): void
    {
        $r = $this->como('responsable.dos@demo.test')->get('api/v1/configuracion/recordatorios');

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $this->assertArrayNotHasKey('data', $cuerpo);

        $this->assertSame([
            'dias_antes'                      => [7, 3, 1],
            'dia_compromiso'                  => true,
            'vencido_cada_dias'               => 3,
            'vencido_max_repeticiones'        => 5,
            'resumen_frecuencia'              => 'semanal',
            'solicitud_avances_activa'        => true,
            'invitaciones_calendario_activas' => false,
        ], $cuerpo);
    }

    public function testPutConfigDireccionActualizaLaGlobalConDiasAscendenteYAuditaCambioConfig(): void
    {
        $payload = [
            'dias_antes'               => [1, 3, 7],
            'dia_compromiso'           => false,
            'vencido_cada_dias'        => 2,
            'vencido_max_repeticiones' => 4,
            'resumen_frecuencia'       => 'quincenal',
        ];

        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $this->assertArrayNotHasKey('data', $cuerpo);

        $this->assertSame([7, 3, 1], $cuerpo['dias_antes']); // persistido descendente
        $this->assertFalse($cuerpo['dia_compromiso']);
        $this->assertSame(2, $cuerpo['vencido_cada_dias']);
        $this->assertSame(4, $cuerpo['vencido_max_repeticiones']);
        $this->assertSame('quincenal', $cuerpo['resumen_frecuencia']);
        // Campo opcional omitido en el payload: conserva el valor vigente (seed = true).
        $this->assertTrue($cuerpo['solicitud_avances_activa']);

        $fila = Database::connect()->table('auditoria')->where('accion', 'cambio_config')->get()->getRowArray();
        $this->assertNotNull($fila);
        $this->assertSame('configuracion', $fila['entidad']);

        // Confirma que quedó persistido: un GET posterior devuelve lo mismo.
        $get = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/configuracion/recordatorios'));
        $this->assertSame([7, 3, 1], $get['dias_antes']);
    }

    public function testPutConfigDireccionHabilitaODeshabilitaSolicitudDeAvances(): void
    {
        $payload = [
            'dias_antes'               => [1, 3, 7],
            'dia_compromiso'           => true,
            'vencido_cada_dias'        => 3,
            'vencido_max_repeticiones' => 5,
            'resumen_frecuencia'       => 'semanal',
            'solicitud_avances_activa' => false,
        ];

        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(200);
        $this->assertFalse($this->cuerpo($r)['solicitud_avances_activa']);

        // Persistido: un GET posterior sigue devolviendo el flag deshabilitado.
        $get = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/configuracion/recordatorios'));
        $this->assertFalse($get['solicitud_avances_activa']);
    }

    public function testPutConfigRechazaSolicitudDeAvancesNoBooleana(): void
    {
        $payload = [
            'dias_antes'               => [1, 3, 7],
            'dia_compromiso'           => true,
            'vencido_cada_dias'        => 3,
            'vencido_max_repeticiones' => 5,
            'resumen_frecuencia'       => 'semanal',
            'solicitud_avances_activa' => 'sí',
        ];

        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(422);
        $this->assertArrayHasKey('solicitud_avances_activa', $this->cuerpo($r)['campos']);
    }

    public function testPutConfigDireccionHabilitaODeshabilitaInvitacionesDeCalendario(): void
    {
        // Seed = false; la habilitamos y verificamos round-trip.
        $payload = [
            'dias_antes'                      => [1, 3, 7],
            'dia_compromiso'                  => true,
            'vencido_cada_dias'               => 3,
            'vencido_max_repeticiones'        => 5,
            'resumen_frecuencia'              => 'semanal',
            'invitaciones_calendario_activas' => true,
        ];

        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(200);
        $this->assertTrue($this->cuerpo($r)['invitaciones_calendario_activas']);

        $get = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/configuracion/recordatorios'));
        $this->assertTrue($get['invitaciones_calendario_activas']);
        // La bandera de correos no se ve afectada (se conserva por omisión).
        $this->assertTrue($get['solicitud_avances_activa']);
    }

    public function testPutConfigRechazaInvitacionesDeCalendarioNoBooleana(): void
    {
        $payload = [
            'dias_antes'                      => [1, 3, 7],
            'dia_compromiso'                  => true,
            'vencido_cada_dias'               => 3,
            'vencido_max_repeticiones'        => 5,
            'resumen_frecuencia'              => 'semanal',
            'invitaciones_calendario_activas' => 'sí',
        ];

        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(422);
        $this->assertArrayHasKey('invitaciones_calendario_activas', $this->cuerpo($r)['campos']);
    }

    public function testPutConfigConValorFueraDeRangoDevuelve422(): void
    {
        $payload = [
            'dias_antes'               => [40],
            'dia_compromiso'           => true,
            'vencido_cada_dias'        => 3,
            'vencido_max_repeticiones' => 5,
            'resumen_frecuencia'       => 'semanal',
        ];

        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('dias_antes', $cuerpo['campos']);
    }

    public function testPutConfigConDiasAntesDesordenadoDevuelve422(): void
    {
        $payload = [
            'dias_antes'               => [3, 7, 1],
            'dia_compromiso'           => true,
            'vencido_cada_dias'        => 3,
            'vencido_max_repeticiones' => 5,
            'resumen_frecuencia'       => 'semanal',
        ];

        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('dias_antes', $cuerpo['campos']);
    }

    public function testPutConfigDeNoDireccionDevuelve403YNoModificaNada(): void
    {
        $original = $this->cuerpo($this->como('responsable.dos@demo.test')->get('api/v1/configuracion/recordatorios'));

        $payload = [
            'dias_antes'               => [7, 3, 1],
            'dia_compromiso'           => true,
            'vencido_cada_dias'        => 3,
            'vencido_max_repeticiones' => 5,
            'resumen_frecuencia'       => 'mensual',
        ];
        $r = $this->como('coordinacion.operativa@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(403);
        $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);

        $sigueIgual = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/configuracion/recordatorios'));
        $this->assertSame($original, $sigueIgual);
    }

    /**
     * RE-09: cambiar el default global NO altera `recordatorio_dias` de
     * acuerdos con override (columna independiente) — el acuerdo 4 tiene
     * override `[5,1]` en el seed.
     */
    public function testRE09CambiarElGlobalNoAlteraElOverrideDeUnAcuerdo(): void
    {
        $antes = Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray();
        $this->assertSame([5, 1], json_decode($antes['recordatorio_dias'], true));

        $payload = [
            'dias_antes'               => [2, 4, 6],
            'dia_compromiso'           => true,
            'vencido_cada_dias'        => 3,
            'vencido_max_repeticiones' => 5,
            'resumen_frecuencia'       => 'semanal',
        ];
        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);
        $r->assertStatus(200);

        $despues = Database::connect()->table('acuerdos')->where('id', 4)->get()->getRowArray();
        $this->assertSame($antes['recordatorio_dias'], $despues['recordatorio_dias']);

        // El detalle del acuerdo 4 sigue materializando recordatorios con SU override,
        // no con el nuevo default global.
        $detalle = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos/4'))['data'];
        $tiposPorFecha = array_column($detalle['recordatorios'], 'programado_para', 'tipo');
        $this->assertContains('2026-07-08', $tiposPorFecha); // -1d del override, no del nuevo global
    }

    public function testPutConfigConCamposDesconocidosDevuelve422CampoNoPermitido(): void
    {
        $payload = [
            'dias_antes'               => [7, 3, 1],
            'dia_compromiso'           => true,
            'vencido_cada_dias'        => 3,
            'vencido_max_repeticiones' => 5,
            'resumen_frecuencia'       => 'semanal',
            'campo_extra'              => 'no debería aceptarse',
        ];
        $r = $this->como('direccion@demo.test')->put('api/v1/configuracion/recordatorios', $payload);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }
}
