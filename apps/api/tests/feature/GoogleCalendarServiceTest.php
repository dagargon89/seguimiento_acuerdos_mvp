<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use App\Libraries\Google\GoogleCalendarService;
use App\Services\RecordatorioService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\FakeCalendarApi;
use Tests\Support\FakeMailer;

/**
 * `GoogleCalendarService` (implementa `CalendarSync`, RF-09 / S2.3): crea o
 * actualiza (patch) el evento all-day de un acuerdo contra `FakeCalendarApi`,
 * reconciliando estado/intentos/error en `google_sync`. Casos GC-01..05 del
 * brief de la Tarea 12.
 *
 * Setup igual a RecordatorioJobTest: DatabaseTestTrait + InitialSeeder,
 * namespace App. Cada test crea su propio acuerdo + fila google_sync para
 * determinismo (no depende del seed salvo GC-04-b y GC-05, que sí usan las
 * filas ya sincronizadas/con intentos=3 del seed cuando aplica).
 *
 * @group database
 *
 * @internal
 */
final class GoogleCalendarServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private FakeCalendarApi $api;
    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api  = new FakeCalendarApi();
        $this->conn = Database::connect();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function servicio(): GoogleCalendarService
    {
        return new GoogleCalendarService($this->api, 'calendario-test@planjuarez.org');
    }

    /**
     * Crea un acuerdo de prueba limpio. Devuelve su id.
     */
    private function crearAcuerdo(
        string $fechaCompromiso,
        string $estado = 'en_proceso',
        ?string $tema = 'Prueba',
        string $accion = 'Acción de prueba',
        int $responsableId = 6,
    ): int {
        $this->conn->table('acuerdos')->insert([
            'reunion_id'       => 1,
            'area_id'          => 1,
            'accion'           => $accion,
            'tema'             => $tema,
            'responsable_id'   => $responsableId,
            'capturado_por_id' => 1,
            'fecha_compromiso' => $fechaCompromiso,
            'estado'           => $estado,
            'concluido_por_id' => $estado === 'concluido' ? 1 : null,
            'concluido_at'     => $estado === 'concluido' ? '2026-07-01 10:00:00' : null,
            'created_at'       => '2026-06-01 09:00:00',
        ]);

        return (int) $this->conn->insertID();
    }

    /** Inserta la fila google_sync para un acuerdo con los valores dados. */
    private function crearGoogleSync(
        int $acuerdoId,
        ?string $calendarEventId = null,
        string $estado = 'pendiente',
        int $intentos = 0,
    ): void {
        $this->conn->table('google_sync')->insert([
            'acuerdo_id'        => $acuerdoId,
            'calendar_event_id' => $calendarEventId,
            'estado'            => $estado,
            'intentos'          => $intentos,
            'synced_at'         => null,
            'error'             => null,
        ]);
    }

    private function googleSyncDe(int $acuerdoId): array
    {
        return $this->conn->table('google_sync')->where('acuerdo_id', $acuerdoId)->get()->getRowArray();
    }

    // ── GC-01: sin calendar_event_id → crea evento ────────────────────────────

    public function testGC01CreaEventoCuandoNoHayEventId(): void
    {
        $id = $this->crearAcuerdo('2026-07-20');
        $this->crearGoogleSync($id, calendarEventId: null, estado: 'pendiente');

        $this->servicio()->sincronizar($id);

        $this->assertSame(1, $this->api->llamadasCrear());
        $this->assertSame(0, $this->api->llamadasActualizar());

        $fila = $this->googleSyncDe($id);
        $this->assertSame($this->api->proximoEventId, $fila['calendar_event_id']);
        $this->assertSame('sincronizado', $fila['estado']);
        $this->assertNotNull($fila['synced_at']);
        $this->assertNull($fila['error']);
    }

    // ── GC-02: con calendar_event_id → actualiza (patch), no duplica ─────────

    public function testGC02ActualizaEventoCuandoYaHayEventIdYNoLoCambia(): void
    {
        $id = $this->crearAcuerdo('2026-08-10');
        $this->crearGoogleSync($id, calendarEventId: 'evt-existente-123', estado: 'pendiente');

        $this->servicio()->sincronizar($id);

        $this->assertSame(0, $this->api->llamadasCrear());
        $this->assertSame(1, $this->api->llamadasActualizar());
        $this->assertSame('evt-existente-123', $this->api->actualizados[0]['eventId']);

        $fila = $this->googleSyncDe($id);
        $this->assertSame('evt-existente-123', $fila['calendar_event_id'], 'no cambia, no se duplica');
        $this->assertSame('sincronizado', $fila['estado']);
        $this->assertNotNull($fila['synced_at']);
        $this->assertNull($fila['error']);
    }

    // ── GC-03: acuerdo concluido → título con [Concluido] y colorId '8' ───────

    public function testGC03AcuerdoConcluidoUsaPrefijoYColorNeutro(): void
    {
        $id = $this->crearAcuerdo(
            '2026-07-15',
            estado: 'concluido',
            tema: 'Tema X',
            accion: 'Hacer algo',
        );
        $this->crearGoogleSync($id, calendarEventId: 'evt-concluido-1', estado: 'pendiente');

        $this->servicio()->sincronizar($id);

        $this->assertSame(1, $this->api->llamadasActualizar());
        $evento = $this->api->actualizados[0]['evento'];
        $this->assertStringStartsWith('[Concluido] [Tema X] Hacer algo — ', $evento['summary']);
        $this->assertSame('8', $evento['colorId']);
    }

    // ── GC-04: error en crearEvento → estado error, intentos+1, no propaga ───

    public function testGC04ErrorEnCrearEventoMarcaFilaComoErrorSinPropagar(): void
    {
        $id = $this->crearAcuerdo('2026-07-25');
        $this->crearGoogleSync($id, calendarEventId: null, estado: 'pendiente', intentos: 1);
        $this->api->fallarEnCrear('boom de la API');

        $this->servicio()->sincronizar($id); // no debe lanzar

        $fila = $this->googleSyncDe($id);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame(2, (int) $fila['intentos']);
        $this->assertSame('boom de la API', $fila['error']);
        $this->assertNull($fila['calendar_event_id']);
        $this->assertNull($fila['synced_at']);
    }

    /** Sub-caso GC-04: intentos ya en 3 → el JOB no debe procesar esa fila. */
    public function testGC04FilaConTresIntentosNoSeProcesaEnElJob(): void
    {
        // Aísla el caso: el seed trae otras filas pendiente/error (intentos<3,
        // ver RecordatorioJobTest) que el job SÍ procesaría; se reconcilian a
        // 'sincronizado' para que la única candidata sea la de este test.
        $this->conn->table('google_sync')->set(['estado' => 'sincronizado'])->where('estado !=', 'sincronizado')->update();

        $id = $this->crearAcuerdo('2026-07-25');
        $this->crearGoogleSync($id, calendarEventId: null, estado: 'error', intentos: 3);

        $servicio = new RecordatorioService(new FakeMailer(), $this->servicio());
        $servicio->procesar(new \DateTimeImmutable('2026-07-14'));

        $this->assertSame(0, $this->api->llamadasCrear());
        $this->assertSame(0, $this->api->llamadasActualizar());
    }

    // ── GC-05: todas sincronizadas → el job no llama a la API ────────────────

    public function testGC05JobNoLlamaApiCuandoTodoEstaSincronizado(): void
    {
        // Deja SOLO filas sincronizadas: reconcilia las del seed y agrega una propia.
        $this->conn->table('google_sync')->set(['estado' => 'sincronizado'])->where('estado !=', 'sincronizado')->update();
        $id = $this->crearAcuerdo('2026-07-25');
        $this->crearGoogleSync($id, calendarEventId: 'evt-ya-sync', estado: 'sincronizado');

        $servicio = new RecordatorioService(new FakeMailer(), $this->servicio());
        $servicio->procesar(new \DateTimeImmutable('2026-07-14'));

        $this->assertSame(0, $this->api->llamadasCrear());
        $this->assertSame(0, $this->api->llamadasActualizar());
    }
}
