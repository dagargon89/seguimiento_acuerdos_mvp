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
 * Endpoints de ESCRITURA (Tarea 6 / S1.5): POST /acuerdos/lote,
 * PATCH /acuerdos/{id}, PUT /acuerdos/{id}/corresponsables,
 * POST /acuerdos/{id}/avances. Máquina de estados de escritura (RF-02,
 * RF-05, RF-07), permisos por rol (SRS §2.2) y reglas no negociables de
 * CLAUDE.md (transacciones, `estado` nunca del cliente, corresponsable ≠
 * responsable, visibilidad server-side, auditoría).
 *
 * Los IDs de caso entre paréntesis referencian el doc 06 (plan de pruebas) y
 * el brief de la Tarea 6 (LT-0x, ME-0x, AU-0x, OW-0x).
 *
 * @group database
 *
 * @internal
 */
final class AcuerdosEscrituraTest extends CIUnitTestCase
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
        $this->withBodyFormat('json');

        return $this;
    }

    private function cuerpo(TestResponse $r): array
    {
        return json_decode($r->response()->getJSON(), true);
    }

    private function hoy(): string
    {
        return Time::now()->toDateString();
    }

    private function manana(): string
    {
        return Time::now()->addDays(1)->toDateString();
    }

    private function ayer(): string
    {
        return Time::now()->subDays(1)->toDateString();
    }

    private function conteos(): array
    {
        $db = Database::connect();

        return [
            'reuniones'               => $db->table('reuniones')->countAllResults(),
            'acuerdos'                => $db->table('acuerdos')->countAllResults(),
            'acuerdo_corresponsables' => $db->table('acuerdo_corresponsables')->countAllResults(),
            'google_sync'             => $db->table('google_sync')->countAllResults(),
            'auditoria'               => $db->table('auditoria')->countAllResults(),
        ];
    }

    private function loteValido(array $overrides = []): array
    {
        return array_replace_recursive([
            'reunion' => ['nombre' => 'Reunión de prueba LT', 'fecha' => $this->hoy()],
            'acuerdos' => [
                [
                    'tema' => 'Tema uno', 'accion' => 'Acción uno', 'responsable_id' => 4,
                    'corresponsables_ids' => [], 'area_id' => 1, 'fecha_compromiso' => $this->manana(),
                    'enlace' => null, 'observaciones' => null, 'recordatorio_dias' => [5, 1],
                ],
                [
                    'tema' => null, 'accion' => 'Acción dos', 'responsable_id' => 5,
                    'corresponsables_ids' => [6], 'area_id' => 1, 'fecha_compromiso' => $this->manana(),
                    'enlace' => null, 'observaciones' => null, 'recordatorio_dias' => null,
                ],
                [
                    'tema' => 'Tema tres', 'accion' => 'Acción tres', 'responsable_id' => 6,
                    'corresponsables_ids' => [], 'area_id' => 2, 'fecha_compromiso' => $this->hoy(),
                    'enlace' => 'https://example.test/tres', 'observaciones' => 'obs', 'recordatorio_dias' => [],
                ],
            ],
        ], $overrides);
    }

    // ── LT-01: lote válido de 3 → 201 y 3 ids; reunión creada ────────────

    public function testLT01LoteValidoDeTresCreaAcuerdosYReunion(): void
    {
        $antes = $this->conteos();

        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $this->loteValido());

        $r->assertStatus(201);
        $cuerpo = $this->cuerpo($r);
        $this->assertCount(3, $cuerpo['data']);

        $ids = array_map(static fn (array $a) => $a['id'], $cuerpo['data']);
        $this->assertCount(3, array_unique($ids));

        foreach ($cuerpo['data'] as $a) {
            $this->assertSame('en_proceso', $a['estado']);
        }

        $despues = $this->conteos();
        $this->assertSame($antes['reuniones'] + 1, $despues['reuniones']);
        $this->assertSame($antes['acuerdos'] + 3, $despues['acuerdos']);
        $this->assertSame($antes['google_sync'] + 3, $despues['google_sync']);
        $this->assertSame($antes['acuerdo_corresponsables'] + 1, $despues['acuerdo_corresponsables']);
        $this->assertSame($antes['auditoria'] + 3, $despues['auditoria']);

        // google_sync: una fila 'pendiente' por acuerdo creado.
        $db    = Database::connect();
        $syncs = $db->table('google_sync')->whereIn('acuerdo_id', $ids)->get()->getResultArray();
        $this->assertCount(3, $syncs);
        foreach ($syncs as $s) {
            $this->assertSame('pendiente', $s['estado']);
        }
    }

    public function testLT01ReutilizaReunionExistentePorNombreYFecha(): void
    {
        $lote1 = $this->loteValido(['acuerdos' => [$this->loteValido()['acuerdos'][0]]]);
        $r1    = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote1);
        $r1->assertStatus(201);
        $reunionId1 = $this->cuerpo($r1)['data'][0]['reunion']['id'];

        $antes = $this->conteos();
        $lote2 = $this->loteValido(['acuerdos' => [$this->loteValido()['acuerdos'][1]]]);
        $r2    = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote2);
        $r2->assertStatus(201);
        $reunionId2 = $this->cuerpo($r2)['data'][0]['reunion']['id'];

        $this->assertSame($reunionId1, $reunionId2);
        $despues = $this->conteos();
        $this->assertSame($antes['reuniones'], $despues['reuniones']); // no se creó una segunda reunión.
    }

    // ── LT-02: un renglón inválido → 422 y CERO filas persistidas ────────

    public function testLT02UnRenglonInvalidoNoPersisteNadaDelLote(): void
    {
        $antes = $this->conteos();

        $lote = $this->loteValido();
        $lote['acuerdos'][2]['fecha_compromiso'] = $this->ayer(); // renglón 2 inválido.

        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('validacion', $cuerpo['error']);
        $this->assertArrayHasKey('acuerdos.2.fecha_compromiso', $cuerpo['campos']);

        $despues = $this->conteos();
        $this->assertSame($antes, $despues, 'Cero filas deben persistirse en cualquier tabla si un renglón es inválido.');
    }

    public function testLT02VariosRenglonesInvalidosReportanTodosLosCampos(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['fecha_compromiso'] = $this->ayer();
        $lote['acuerdos'][2]['responsable_id']   = 9999;

        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertArrayHasKey('acuerdos.0.fecha_compromiso', $cuerpo['campos']);
        $this->assertArrayHasKey('acuerdos.2.responsable_id', $cuerpo['campos']);
    }

    // ── LT-03: corresponsable == responsable (o duplicado) → 422 ────────

    public function testLT03CorresponsableIgualAResponsableEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][1]['corresponsables_ids'] = [5]; // 5 es su propio responsable_id.

        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertArrayHasKey('acuerdos.1.corresponsables_ids', $cuerpo['campos']);
    }

    public function testLT03CorresponsablesDuplicadosEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][1]['corresponsables_ids'] = [6, 6];

        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $cuerpo = $this->cuerpo($r);
        $this->assertArrayHasKey('acuerdos.1.corresponsables_ids', $cuerpo['campos']);
    }

    // ── LT-04: captura concurrente de dos usuarios distintos no interfiere ──

    public function testLT04CapturaConcurrenteDeDosUsuariosNoInterfiere(): void
    {
        $loteA = $this->loteValido(['reunion' => ['nombre' => 'Reunión concurrente A', 'fecha' => $this->hoy()]]);
        $loteB = $this->loteValido(['reunion' => ['nombre' => 'Reunión concurrente B', 'fecha' => $this->hoy()]]);

        // `service('response')` es compartido: hay que leer el cuerpo de CADA
        // respuesta inmediatamente después de su propia request — si se difiere
        // la lectura hasta después de la segunda llamada, ambas `TestResponse`
        // envuelven el mismo objeto Response singleton y se leería dos veces el
        // cuerpo de B. Por eso NO se decodifica $rA hasta haber hecho la request B.
        $rA    = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $loteA);
        $rA->assertStatus(201);
        $idsA  = array_map(static fn (array $a) => $a['id'], $this->cuerpo($rA)['data']);

        $rB    = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/lote', $loteB);
        $rB->assertStatus(201);
        $idsB  = array_map(static fn (array $a) => $a['id'], $this->cuerpo($rB)['data']);

        $this->assertEmpty(array_intersect($idsA, $idsB), 'Los ids creados por cada captura no deben mezclarse.');

        // Cada acuerdo quedó con el capturado_por_id correcto y su propia reunión.
        $db      = Database::connect();
        $filasA  = $db->table('acuerdos')->whereIn('id', $idsA)->get()->getResultArray();
        $filasB  = $db->table('acuerdos')->whereIn('id', $idsB)->get()->getResultArray();
        foreach ($filasA as $f) {
            $this->assertSame(4, (int) $f['capturado_por_id']); // responsable.uno@demo.test = id 4.
        }
        foreach ($filasB as $f) {
            $this->assertSame(5, (int) $f['capturado_por_id']); // responsable.dos@demo.test = id 5.
        }
    }

    // ── LT-05: recordatorio_dias inválido ────────────────────────────────

    public function testLT05RecordatorioDiasFueraDeRangoEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['recordatorio_dias'] = [40];

        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $this->assertArrayHasKey('acuerdos.0.recordatorio_dias', $this->cuerpo($r)['campos']);
    }

    public function testLT05RecordatorioDiasNoArrayEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['recordatorio_dias'] = 'no-es-array';

        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $this->assertArrayHasKey('acuerdos.0.recordatorio_dias', $this->cuerpo($r)['campos']);
    }

    // ── ME-01: el acuerdo nace en_proceso ─────────────────────────────────

    public function testME01AcuerdoNaceEnProceso(): void
    {
        $lote = $this->loteValido(['acuerdos' => [$this->loteValido()['acuerdos'][0]]]);
        $r    = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(201);
        $this->assertSame('en_proceso', $this->cuerpo($r)['data'][0]['estado']);
    }

    // ── ME-04 / ME-04b / ME-05 / ME-06: avances y reprogramación sobre vencido ──

    public function testME04AvanceConNuevaFechaFuturaSobreVencidoPasaAEnProcesoYRecalculaRecordatorios(): void
    {
        // Acuerdo 3 (área 1, responsable 4): vencido en el seed.
        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/3/avances', [
            'descripcion' => 'Se reprograma por retraso del proveedor', 'nueva_fecha' => $this->manana(),
        ]);

        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('en_proceso', $data['estado']);
        $this->assertSame($this->manana(), $data['fecha_compromiso']);

        $tipos = array_map(static fn (array $a) => $a['tipo'], $data['avances']);
        $this->assertContains('reprogramacion', $tipos);

        // recordatorios recalculados sobre la NUEVA fecha (dia == nueva_fecha).
        $porTipo = [];
        foreach ($data['recordatorios'] as $rec) {
            $porTipo[$rec['tipo']] = $rec['programado_para'];
        }
        $this->assertSame($this->manana(), $porTipo['dia'] ?? null);

        // BD: sync marcado pendiente de nuevo.
        $sync = Database::connect()->table('google_sync')->where('acuerdo_id', 3)->get()->getRowArray();
        $this->assertSame('pendiente', $sync['estado']);
    }

    public function testME04bNuevaFechaIgualAHoySobreVencidoPasaAEnProceso(): void
    {
        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/3/avances', [
            'descripcion' => 'Se retoma hoy mismo', 'nueva_fecha' => $this->hoy(),
        ]);

        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('en_proceso', $data['estado']);
        $this->assertSame($this->hoy(), $data['fecha_compromiso']);
    }

    public function testME05AvanceSinNuevaFechaSobreVencidoSigueVencido(): void
    {
        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/3/avances', [
            'descripcion' => 'Seguimos en espera de los proveedores, sin cambio de fecha.',
        ]);

        $r->assertStatus(200);
        $data = $this->cuerpo($r)['data'];
        $this->assertSame('vencido', $data['estado']);
        $this->assertSame('2026-07-06', $data['fecha_compromiso']); // sin cambios.

        $tipos = array_map(static fn (array $a) => $a['tipo'], $data['avances']);
        $this->assertContains('avance', $tipos);
        $this->assertNotContains('reprogramacion', $tipos);
    }

    public function testME06NuevaFechaPasadaEs422(): void
    {
        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/3/avances', [
            'descripcion' => 'Intento inválido', 'nueva_fecha' => $this->ayer(),
        ]);

        $r->assertStatus(422);
        $this->assertArrayHasKey('nueva_fecha', $this->cuerpo($r)['campos']);

        // BD intacta: sigue vencido (crudo en el seed), sin fecha_compromiso modificada.
        $fila = Database::connect()->table('acuerdos')->where('id', 3)->get()->getRowArray();
        $this->assertSame('vencido', $fila['estado']);
        $this->assertSame('2026-07-06', $fila['fecha_compromiso']);
    }

    // ── ME-11: body con estado → 422 campo_no_permitido ──────────────────

    public function testME11PatchConEstadoEsCampoNoPermitido(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', ['estado' => 'concluido']);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    public function testME11LoteConEstadoEnUnRenglonEsCampoNoPermitido(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['estado'] = 'vencido';

        $r = $this->como('responsable.uno@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);

        // No debe haberse creado nada.
        $db = Database::connect();
        $this->assertSame(0, $db->table('acuerdos')->like('accion', 'Acción uno')->countAllResults());
    }

    // ── avance en concluido → 409 ──────────────────────────────────────

    public function testAvanceSobreAcuerdoConcluidoEs409(): void
    {
        // Acuerdo 1: concluido en el seed.
        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/1/avances', [
            'descripcion' => 'Intento de avance sobre concluido',
        ]);

        $r->assertStatus(409);
        $this->assertSame('estado_invalido', $this->cuerpo($r)['error']);
    }

    // ── AU-04: coordinador edita acuerdo de OTRA área → 403 ──────────────

    public function testAU04CoordinadorEditaAcuerdoDeOtraAreaEs403(): void
    {
        // Camilo (coordinador, área 2) ES RESPONSABLE del acuerdo 2 (área 1) — por
        // eso lo VE (visibilidad por participación, no por área) — pero como el
        // acuerdo es de otra área, editarlo debe seguir siendo 403 (no es Dirección
        // ni coordinación DEL ÁREA del acuerdo). Usamos un acuerdo abierto: el 4 no
        // sirve (área 1, Camilo no participa); insertamos uno controlado para no
        // depender de que el único caso del seed esté concluido.
        $db  = Database::connect();
        $db->table('acuerdos')->insert([
            'reunion_id' => 1, 'area_id' => 1, 'accion' => 'Acuerdo AU-04 de prueba',
            'responsable_id' => 3, 'capturado_por_id' => 1,
            'fecha_compromiso' => $this->manana(), 'estado' => 'en_proceso',
            'created_at' => Time::now()->toDateTimeString(),
        ]);
        $id = (int) $db->insertID();

        $r = $this->como('coordinacion.vinculacion@demo.test')->patch("api/v1/acuerdos/{$id}", ['tema' => 'Intento ajeno']);

        $r->assertStatus(403);
    }

    /**
     * ADR-007 (visibilidad abierta, 2026-07-10): antes de ADR-007 este caso
     * respondía 404 (el acuerdo 6, área 2, no era visible para Carla —
     * coordinadora del área 1, sin participar — y la visibilidad manda sobre
     * el permiso). Con la visibilidad de lectura abierta, Carla SÍ ve el
     * acuerdo 6 (200 en GET), así que la request llega al guard de ESCRITURA
     * (`puedeEditarEstructura`), que sigue exigiendo Dirección o coordinación
     * DEL ÁREA del acuerdo — ese guard NO cambió, por eso ahora es 403 (no
     * 404). La restricción de edición es exactamente la misma; solo cambia
     * el código porque el recurso ya no está oculto.
     */
    public function testAU04CoordinadorEditaAcuerdoDeOtraAreaSinParticiparEs403TrasVisibilidadAbierta(): void
    {
        // Carla (coordinadora, área 1): visibilidad abierta → GET del acuerdo 6 (área 2) es 200.
        $lectura = $this->como('coordinacion.operativa@demo.test')->get('api/v1/acuerdos/6');
        $lectura->assertStatus(200);

        // Pero PATCH sigue restringido: no es Dirección ni coordinación DEL ÁREA del acuerdo → 403.
        $r = $this->como('coordinacion.operativa@demo.test')->patch('api/v1/acuerdos/6', ['tema' => 'Intento ajeno']);

        $r->assertStatus(403);
        $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);
    }

    // ── AU-05: corresponsable registra avance → 200 ──────────────────────

    public function testAU05CorresponsableRegistraAvanceEs200(): void
    {
        // Acuerdo 3: corresponsable_id 5 (Rafael, responsable.dos@demo.test).
        $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/3/avances', [
            'descripcion' => 'Avance registrado por el corresponsable',
        ]);

        $r->assertStatus(200);
    }

    // ── AU-06: corresponsable intenta PATCH (responsable/área) → 403 ────

    public function testAU06CorresponsableIntentaPatchEs403(): void
    {
        // Acuerdo 3: corresponsable_id 5, pero NO es dirección ni coordinación del área.
        $r = $this->como('responsable.dos@demo.test')->patch('api/v1/acuerdos/3', ['responsable_id' => 5]);

        $r->assertStatus(403);
    }

    public function testAU06CorresponsableIntentaCorresponsablesEs403(): void
    {
        $r = $this->como('responsable.dos@demo.test')->put('api/v1/acuerdos/3/corresponsables', ['usuarios_ids' => [6]]);

        $r->assertStatus(403);
    }

    // ── Acuerdo inexistente → 404; ajeno-pero-visible (ADR-007) → 403 en escritura ──

    public function testEscrituraSobreAcuerdoInexistenteEs404(): void
    {
        $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/9999/avances', ['descripcion' => 'no debería poder']);

        $r->assertStatus(404);
    }

    /**
     * ADR-007 (visibilidad abierta): antes de ADR-007 este caso respondía 404
     * (el acuerdo 6, área 2, no era visible para Rafael — responsable, no
     * participa ni es de esa área). Con la visibilidad de lectura abierta,
     * Rafael SÍ ve el acuerdo 6 (200 en GET), así que el intento de avance
     * llega al guard de ESCRITURA (`puedeRegistrarAvance`), que sigue
     * exigiendo ser responsable/corresponsable/coordinación del área/
     * Dirección — ese guard NO cambió, por eso ahora es 403 (no 404).
     */
    public function testEscrituraSobreAcuerdoAjenoVisibleEs403TrasVisibilidadAbierta(): void
    {
        // Acuerdo 6 (área 2): Rafael (responsable.dos, id 5) no participa ni es de esa área.
        $lectura = $this->como('responsable.dos@demo.test')->get('api/v1/acuerdos/6');
        $lectura->assertStatus(200);

        $r = $this->como('responsable.dos@demo.test')->post('api/v1/acuerdos/6/avances', ['descripcion' => 'no debería poder']);

        $r->assertStatus(403);
        $this->assertSame('sin_permiso', $this->cuerpo($r)['error']);
    }

    // ── OW-01: SQLi en q/textos no rompe; se guarda literal ──────────────

    public function testOW01SqlInyectadoEnAccionSeGuardaLiteralYNoRompeNada(): void
    {
        $payload = "'; DROP TABLE acuerdos; --";
        $lote    = $this->loteValido();
        $lote['acuerdos'][0]['accion'] = $payload;

        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(201);
        $this->assertSame($payload, $this->cuerpo($r)['data'][0]['accion']);

        // La tabla sigue viva y el listado sigue funcionando.
        $db = Database::connect();
        $this->assertGreaterThan(0, $db->table('acuerdos')->countAllResults());
    }

    // ── OW-02: XSS almacenado se persiste como texto (React escapa en render) ──

    public function testOW02XssEnObservacionesSePersisteComoTextoLiteral(): void
    {
        $xss  = '<script>alert(1)</script>';
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['observaciones'] = $xss;

        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(201);
        $this->assertSame($xss, $this->cuerpo($r)['data'][0]['observaciones']);
    }

    // ── OW-06: enlace no http(s) → 422 ────────────────────────────────────

    public function testOW06EnlaceJavascriptEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['enlace'] = 'javascript:alert(1)';

        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $this->assertArrayHasKey('acuerdos.0.enlace', $this->cuerpo($r)['campos']);
    }

    public function testOW06EnlaceNoHttpEnPatchEs422(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', ['enlace' => 'ftp://example.test/x']);

        $r->assertStatus(422);
        $this->assertArrayHasKey('enlace', $this->cuerpo($r)['campos']);
    }

    // ── enlaces (múltiples): creación, normalización y validación ──────────

    public function testEnlacesMultiplesSeCreanYNormalizan(): void
    {
        $lote = $this->loteValido();
        // Incluye duplicado y una cadena vacía: deben deduplicarse/descartarse.
        $lote['acuerdos'][0]['enlaces'] = [
            'https://drive.example/minuta',
            '  https://fotos.example/jornada  ',
            'https://drive.example/minuta',
            '',
        ];

        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(201);
        $this->assertSame(
            ['https://drive.example/minuta', 'https://fotos.example/jornada'],
            $this->cuerpo($r)['data'][0]['enlaces'],
        );
    }

    public function testAcuerdoSinEnlacesDevuelveListaVacia(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $this->loteValido());

        $r->assertStatus(201);
        $this->assertSame([], $this->cuerpo($r)['data'][0]['enlaces']);
    }

    public function testEnlacesConUrlInvalidaEnLoteEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['enlaces'] = ['https://ok.example', 'javascript:alert(1)'];

        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $this->assertArrayHasKey('acuerdos.0.enlaces', $this->cuerpo($r)['campos']);
    }

    public function testEnlacesReemplazanEnPatch(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', [
            'enlaces' => ['https://uno.example', 'https://dos.example'],
        ]);

        $r->assertStatus(200);
        $this->assertSame(['https://uno.example', 'https://dos.example'], $this->cuerpo($r)['data']['enlaces']);

        // Reemplazo por lista vacía → sin enlaces.
        $r2 = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', ['enlaces' => []]);
        $r2->assertStatus(200);
        $this->assertSame([], $this->cuerpo($r2)['data']['enlaces']);
    }

    public function testEnlacesInvalidoEnPatchEs422(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', ['enlaces' => ['ftp://malo.example']]);

        $r->assertStatus(422);
        $this->assertArrayHasKey('enlaces', $this->cuerpo($r)['campos']);
    }

    // ── OW-08: campo extra desconocido → 422 ──────────────────────────────

    public function testOW08CampoExtraDesconocidoEnLoteEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['concluido_por_id'] = 1;

        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    public function testOW08CampoExtraDesconocidoEnPatchEs422(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', ['campo_inventado' => 'x']);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    public function testOW08CampoExtraDesconocidoEnAvancesEs422(): void
    {
        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/4/avances', [
            'descripcion' => 'x', 'usuario_id' => 99,
        ]);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    public function testOW08CampoExtraDesconocidoEnCorresponsablesEs422(): void
    {
        $r = $this->como('direccion@demo.test')->put('api/v1/acuerdos/4/corresponsables', [
            'usuarios_ids' => [6], 'extra' => 'x',
        ]);

        $r->assertStatus(422);
        $this->assertSame('campo_no_permitido', $this->cuerpo($r)['error']);
    }

    // ── PATCH feliz: dirección edita, respuesta forma Acuerdo, auditoría ──

    public function testPatchDireccionEditaTemaYAccionYAuditaCambio(): void
    {
        $antesAud = $this->conteos()['auditoria'];

        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', [
            'tema' => 'Tema editado', 'accion' => 'Acción editada',
        ]);

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $this->assertSame('Tema editado', $cuerpo['data']['tema']);
        $this->assertSame('Acción editada', $cuerpo['data']['accion']);
        $this->assertArrayNotHasKey('avances', $cuerpo['data']); // PATCH devuelve Acuerdo, no AcuerdoDetalle.

        $this->assertSame($antesAud + 1, $this->conteos()['auditoria']);

        $sync = Database::connect()->table('google_sync')->where('acuerdo_id', 4)->get()->getRowArray();
        $this->assertSame('pendiente', $sync['estado']);
    }

    public function testPatchRecordatorioDiasSePersisteYSeLeeComoArrayDeEnteros(): void
    {
        // Acuerdo 3: recordatorio_dias null en el seed.
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/3', ['recordatorio_dias' => [7, 2]]);

        $r->assertStatus(200);
        $this->assertSame([7, 2], $this->cuerpo($r)['data']['recordatorio_dias']);

        // Releer vía GET confirma que quedó bien serializado en la columna JSON.
        $detalle = $this->cuerpo($this->como('direccion@demo.test')->get('api/v1/acuerdos/3'));
        $this->assertSame([7, 2], $detalle['data']['recordatorio_dias']);
    }

    public function testPatchRecordatorioDiasNullLoLimpia(): void
    {
        // Acuerdo 4: recordatorio_dias [5,1] en el seed.
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', ['recordatorio_dias' => null]);

        $r->assertStatus(200);
        $this->assertNull($this->cuerpo($r)['data']['recordatorio_dias']);
    }

    public function testPatchCoordinadorDeSuAreaPuedeEditar(): void
    {
        // Carla (área 1) edita el acuerdo 4 (área 1).
        $r = $this->como('coordinacion.operativa@demo.test')->patch('api/v1/acuerdos/4', ['observaciones' => 'Nota de coordinación']);

        $r->assertStatus(200);
        $this->assertSame('Nota de coordinación', $this->cuerpo($r)['data']['observaciones']);
    }

    public function testPatchResponsableIdIgualAUnCorresponsableActualEs422(): void
    {
        // Acuerdo 4: corresponsables [4, 6]. Intentar poner responsable_id=4 debe fallar.
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/4', ['responsable_id' => 4]);

        $r->assertStatus(422);
        $this->assertArrayHasKey('responsable_id', $this->cuerpo($r)['campos']);
    }

    public function testPatchAcuerdoInexistenteEs404(): void
    {
        $r = $this->como('direccion@demo.test')->patch('api/v1/acuerdos/9999', ['tema' => 'x']);

        $r->assertStatus(404);
    }

    // ── PUT corresponsables: reemplazo total, respuesta AcuerdoDetalle ────

    public function testPutCorresponsablesReemplazaElConjuntoCompleto(): void
    {
        // Acuerdo 4: corresponsables iniciales [4, 6]. Reemplazamos por [6] solamente.
        $r = $this->como('direccion@demo.test')->put('api/v1/acuerdos/4/corresponsables', ['usuarios_ids' => [6]]);

        $r->assertStatus(200);
        $cuerpo = $this->cuerpo($r);
        $ids    = array_map(static fn (array $c) => $c['id'], $cuerpo['data']['corresponsables']);
        $this->assertSame([6], $ids);
        $this->assertArrayHasKey('avances', $cuerpo['data']); // AcuerdoDetalle.

        $filas = Database::connect()->table('acuerdo_corresponsables')->where('acuerdo_id', 4)->get()->getResultArray();
        $this->assertCount(1, $filas);
    }

    public function testPutCorresponsablesConResponsableComoCorresponsableEs422(): void
    {
        $r = $this->como('direccion@demo.test')->put('api/v1/acuerdos/4/corresponsables', ['usuarios_ids' => [5]]); // 5 es el responsable de 4.

        $r->assertStatus(422);
        $this->assertArrayHasKey('usuarios_ids', $this->cuerpo($r)['campos']);
    }

    public function testPutCorresponsablesConDuplicadosEs422(): void
    {
        $r = $this->como('direccion@demo.test')->put('api/v1/acuerdos/4/corresponsables', ['usuarios_ids' => [6, 6]]);

        $r->assertStatus(422);
        $this->assertArrayHasKey('usuarios_ids', $this->cuerpo($r)['campos']);
    }

    public function testPutCorresponsablesVacioLosEliminaTodos(): void
    {
        $r = $this->como('direccion@demo.test')->put('api/v1/acuerdos/4/corresponsables', ['usuarios_ids' => []]);

        $r->assertStatus(200);
        $this->assertSame([], $this->cuerpo($r)['data']['corresponsables']);
    }

    // ── Usuarios/áreas inactivos o inexistentes referenciados → 422 ──────

    public function testLoteConResponsableInactivoEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['responsable_id'] = 7; // Bruno Baja, inactivo.

        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $this->assertArrayHasKey('acuerdos.0.responsable_id', $this->cuerpo($r)['campos']);
    }

    public function testLoteConAreaInexistenteEs422(): void
    {
        $lote = $this->loteValido();
        $lote['acuerdos'][0]['area_id'] = 9999;

        $r = $this->como('direccion@demo.test')->post('api/v1/acuerdos/lote', $lote);

        $r->assertStatus(422);
        $this->assertArrayHasKey('acuerdos.0.area_id', $this->cuerpo($r)['campos']);
    }
}
