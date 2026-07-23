<?php

namespace Tests\Feature;

use App\Database\Seeds\InitialSeeder;
use App\Services\RecordatorioService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use Tests\Support\FakeCalendarSync;
use Tests\Support\FakeMailer;

/**
 * Job `recordatorios:procesar` (S2.1): RecordatorioService contra dobles
 * (FakeMailer / FakeCalendarSync), SIN credenciales de Google.
 *
 * Casos RE del doc 06 / brief Tarea 10. Para determinismo, cada test que mide
 * materialización crea su PROPIO acuerdo limpio (sin envíos previos del seed) y
 * asevera sobre `recordatorios_enviados` filtrado por ese acuerdo_id.
 *
 * Seed (db.json, "hoy" = 2026-07-09). Config global:
 * dias_antes=[7,3,1], dia_compromiso=true, vencido_cada_dias=3,
 * vencido_max_repeticiones=5, resumen_frecuencia=semanal (=lunes).
 *
 * @group database
 *
 * @internal
 */
final class RecordatorioJobTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = InitialSeeder::class;

    private FakeMailer $mailer;
    private FakeCalendarSync $calendar;
    private \CodeIgniter\Database\BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer   = new FakeMailer();
        $this->calendar = new FakeCalendarSync();
        $this->conn     = Database::connect();
    }

    private function servicio(): RecordatorioService
    {
        return new RecordatorioService($this->mailer, $this->calendar);
    }

    private function correr(string $fecha): void
    {
        $this->servicio()->procesar(new DateTimeImmutable($fecha));
    }

    /**
     * Crea un acuerdo limpio (sin envíos previos). Devuelve su id.
     *
     * @param int[]|null $recordatorioDias
     */
    private function crearAcuerdo(
        int $responsableId,
        string $fechaCompromiso,
        string $estado = 'en_proceso',
        ?array $recordatorioDias = null,
        int $areaId = 1,
    ): int {
        $this->conn->table('acuerdos')->insert([
            'reunion_id'        => 1,
            'area_id'           => $areaId,
            'accion'            => 'Acuerdo de prueba',
            'tema'              => 'Prueba',
            'responsable_id'    => $responsableId,
            'capturado_por_id'  => 1,
            'fecha_compromiso'  => $fechaCompromiso,
            'estado'            => $estado,
            'recordatorio_dias' => $recordatorioDias === null ? null : json_encode($recordatorioDias),
            'created_at'        => '2026-06-01 09:00:00',
        ]);

        return (int) $this->conn->insertID();
    }

    /** @return list<array<string,mixed>> filas de recordatorios_enviados de un acuerdo */
    private function enviosDe(int $acuerdoId): array
    {
        return $this->conn->table('recordatorios_enviados')
            ->where('acuerdo_id', $acuerdoId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /** Cambia la bandera global `solicitud_avances_activa` en `configuracion`. */
    private function setSolicitudAvances(bool $activa): void
    {
        $fila  = $this->conn->table('configuracion')->where('clave', 'recordatorios_default')->get()->getRowArray();
        $valor = json_decode((string) $fila['valor'], true);
        $valor['solicitud_avances_activa'] = $activa;
        $this->conn->table('configuracion')->where('clave', 'recordatorios_default')->update([
            'valor' => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** Cuenta filas `solicitud_avance` de un usuario en una fecha. */
    private function contarSolicitudes(int $usuarioId, string $fecha): int
    {
        return $this->conn->table('recordatorios_enviados')
            ->where('tipo', 'solicitud_avance')
            ->where('programado_para', $fecha)
            ->where('usuario_id', $usuarioId)
            ->countAllResults();
    }

    // ── RE-01: global [7,3,1] + día D → 1 envío por cada una de las 4 fechas ──

    public function testRE01GlobalGeneraUnEnvioEnCadaUnaDeLasCuatroFechas(): void
    {
        // fecha_compromiso 2026-07-20, destinatario único (responsable sin corresponsables).
        $id = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-07-20');

        $fechas = ['2026-07-13', '2026-07-17', '2026-07-19', '2026-07-20']; // -7, -3, -1, D
        foreach ($fechas as $f) {
            $this->correr($f);
        }

        $envios = $this->enviosDe($id);
        $this->assertCount(4, $envios, 'una fila por cada fecha programada');
        $tipos = array_column($envios, 'tipo');
        sort($tipos);
        $this->assertSame(['dia', 'previo', 'previo', 'previo'], $tipos);
        foreach ($envios as $e) {
            $this->assertSame('enviado', $e['estado']);
            $this->assertSame(6, (int) $e['usuario_id']);
        }
    }

    // ── RE-02: override [5,1] ignora el global ────────────────────────────────

    public function testRE02OverrideSoloMaterializaEnMenos5YMenos1(): void
    {
        $id = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-07-20', recordatorioDias: [5, 1]);

        // Con override [5,1] + dia_compromiso: toca en -5 (07-15), -1 (07-19) y D (07-20).
        // NO debe tocar en -7 (07-13) ni -3 (07-17), que sí tocaría el global.
        foreach (['2026-07-13', '2026-07-15', '2026-07-17', '2026-07-19', '2026-07-20'] as $f) {
            $this->correr($f);
        }

        $fechas = array_column($this->enviosDe($id), 'programado_para');
        sort($fechas);
        $this->assertSame(['2026-07-15', '2026-07-19', '2026-07-20'], $fechas);
    }

    // ── RE-03: destinatarios = responsable + corresponsables ──────────────────

    public function testRE03DestinatariosSonResponsableMasCorresponsables(): void
    {
        // Acuerdo 4 del seed: responsable 5, corresponsables 4 y 6, override [5,1],
        // fecha_compromiso 2026-07-09. En D (2026-07-09) toca 'dia' para los 3.
        $this->correr('2026-07-09');

        $delDia = array_filter(
            $this->enviosDe(4),
            static fn (array $e) => $e['programado_para'] === '2026-07-09' && $e['tipo'] === 'dia',
        );
        $destinatarios = array_map(static fn (array $e) => (int) $e['usuario_id'], $delDia);
        sort($destinatarios);
        $this->assertSame([4, 5, 6], $destinatarios);
    }

    // ── RE-04: re-ejecutar el mismo día NO duplica ────────────────────────────

    public function testRE04ReejecutarElMismoDiaNoDuplica(): void
    {
        $id = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-07-20');

        $this->correr('2026-07-13'); // -7
        $conteoTras1 = count($this->enviosDe($id));
        $this->assertSame(1, $conteoTras1);

        // Re-ejecutar el MISMO día: no debe insertar otra fila.
        $this->correr('2026-07-13');
        $this->correr('2026-07-13');
        $envios = $this->enviosDe($id);
        $this->assertCount(1, $envios, 'idempotencia por UNIQUE natural');

        // Y el envío no se re-dispara (sigue 1 correo enviado para ESTE acuerdo).
        // Nota: no se usa $this->mailer->contarPara(email) porque el seed asigna el
        // mismo destinatario (usuario 6) a otros acuerdos (4 y 7) que también
        // generan envíos el 2026-07-13; contar por email global mezclaría acuerdos.
        $enviados = array_filter($envios, static fn (array $e) => $e['estado'] === 'enviado');
        $this->assertCount(1, $enviados);
    }

    // ── RE-05: acuerdo concluido NO genera envíos ─────────────────────────────

    public function testRE05AcuerdoConcluidoNoGeneraEnvios(): void
    {
        // Acuerdo 2 del seed: concluido, fecha 2026-07-05. Correr en -7/-3/-1/D no genera nada.
        foreach (['2026-06-28', '2026-07-02', '2026-07-04', '2026-07-05'] as $f) {
            $this->correr($f);
        }
        $this->assertCount(0, $this->enviosDe(2));
    }

    // ── RE-06: reprogramar regenera futuros y no reenvía los viejos ───────────

    public function testRE06ReprogramarRegeneraFuturosSinReenviarViejos(): void
    {
        $id = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-07-20');

        $this->correr('2026-07-13'); // -7 respecto a 07-20
        $viejos = array_column($this->enviosDe($id), 'programado_para');
        $this->assertSame(['2026-07-13'], $viejos);

        // Reprogramar a futuro (2026-08-10). RF-05: vencido→en_proceso implícito; aquí ya está en_proceso.
        $this->conn->table('acuerdos')->where('id', $id)->update(['fecha_compromiso' => '2026-08-10']);

        // El envío viejo permanece intacto; los nuevos se generan sobre la nueva fecha.
        $this->correr('2026-08-03'); // -7 respecto a 08-10
        $envios = $this->enviosDe($id);
        $fechas = array_column($envios, 'programado_para');
        sort($fechas);
        $this->assertSame(['2026-07-13', '2026-08-03'], $fechas, 'viejo intacto + nuevo prospectivo');

        // El correo del envío viejo no se re-envió (sigue 1 enviado por el -7 original
        // + 1 por el nuevo = 2, contados solo sobre ESTE acuerdo).
        // Nota: no se usa $this->mailer->contarPara(email) porque el seed asigna el
        // mismo destinatario (usuario 6) al acuerdo 7, que también genera un envío
        // (previo, -3) el 2026-07-13; contar por email global mezclaría acuerdos.
        $enviados = array_filter($envios, static fn (array $e) => $e['estado'] === 'enviado');
        $this->assertCount(2, $enviados);
    }

    // ── RE-07: fallo de Mailer → 'fallido' y el job continúa ──────────────────

    public function testRE07FalloDeMailerDejaFallidoYElRestoSeEnvia(): void
    {
        // Acuerdo con responsable 6 (Rosa) + corresponsables 4 (Rita) y 5 (Rafael).
        $id = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-07-20');
        $this->conn->table('acuerdo_corresponsables')->insertBatch([
            ['acuerdo_id' => $id, 'usuario_id' => 4],
            ['acuerdo_id' => $id, 'usuario_id' => 5],
        ]);

        // Rita (responsable.uno) falla; los demás deben enviarse.
        $this->mailer->fallaPara('responsable.uno@demo.test');

        $this->correr('2026-07-13'); // -7

        $envios = $this->enviosDe($id);
        $this->assertCount(3, $envios);

        $porUsuario = [];
        foreach ($envios as $e) {
            $porUsuario[(int) $e['usuario_id']] = $e;
        }
        // Rita (4) fallida con error; Rosa (6) y Rafael (5) enviados.
        $this->assertSame('fallido', $porUsuario[4]['estado']);
        $this->assertNotNull($porUsuario[4]['error']);
        $this->assertNotSame('pendiente_de_envio', $porUsuario[4]['error']);
        $this->assertSame('enviado', $porUsuario[5]['estado']);
        $this->assertSame('enviado', $porUsuario[6]['estado']);
    }

    // ── RE-08: seguimiento de vencido respeta cadencia y máximo ───────────────

    public function testRE08VencidoRespetaCadenciaYMaximo(): void
    {
        // Acuerdo vencido con fecha_compromiso 2026-07-01. Con vencido_cada_dias=3
        // y max=5, las fechas 'vencido' son: +1,+4,+7,+10,+13 → 07-02,07-05,07-08,07-11,07-14.
        // (offset = i*3 - 3 + 1 para i=1..5).
        $id = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-07-01', estado: 'vencido');

        $todas = ['2026-07-02', '2026-07-05', '2026-07-08', '2026-07-11', '2026-07-14', '2026-07-17'];
        foreach ($todas as $f) {
            $this->correr($f);
        }

        $vencidos = array_values(array_filter(
            $this->enviosDe($id),
            static fn (array $e) => $e['tipo'] === 'vencido',
        ));
        $fechas = array_column($vencidos, 'programado_para');
        sort($fechas);
        // 07-17 (i=6) queda fuera del máximo de 5 repeticiones.
        $this->assertSame(['2026-07-02', '2026-07-05', '2026-07-08', '2026-07-11', '2026-07-14'], $fechas);
        $this->assertCount(5, $fechas);
    }

    // ── RE-10: resumen periódico → dirección + coordinaciones, no responsables ─

    public function testRE10ResumenLlegaADireccionYCoordinacionesNoAResponsables(): void
    {
        // 2026-07-13 es lunes → corresponde a frecuencia 'semanal'.
        $this->assertTrue(RecordatorioService::correspondeAFrecuencia('2026-07-13', 'semanal'));

        $this->correr('2026-07-13');

        $resumenes = $this->conn->table('recordatorios_enviados')
            ->where('tipo', 'resumen')
            ->where('programado_para', '2026-07-13')
            ->get()->getResultArray();

        $usuarios = array_map(static fn (array $e) => (int) $e['usuario_id'], $resumenes);
        sort($usuarios);
        // Dirección (1) + coordinadores (2, 3). Responsables (4,5,6) y el inactivo (7) NO.
        $this->assertSame([1, 2, 3], $usuarios);
        foreach ($resumenes as $r) {
            $this->assertNull($r['acuerdo_id']);
            $this->assertSame('enviado', $r['estado']);
        }
    }

    /**
     * Garantía real de idempotencia del resumen (Hallazgo 2): como
     * `acuerdo_id` es NULL en estas filas, la UNIQUE natural NO las protege
     * (MySQL trata cada NULL como distinto). Lo que evita el duplicado es el
     * check-then-act de `procesarResumenPeriodico()` + que el cron corre con
     * un solo runner. Este test corre el job dos veces seguidas el mismo día
     * de resumen y verifica que NO se duplica ni se re-envía.
     */
    public function testResumenNoSeDuplicaAlReejecutarElMismoDia(): void
    {
        $this->correr('2026-07-13'); // lunes → dispara resumen.
        $this->correr('2026-07-13'); // re-ejecución del MISMO día.

        $resumenes = $this->conn->table('recordatorios_enviados')
            ->where('tipo', 'resumen')
            ->where('programado_para', '2026-07-13')
            ->get()->getResultArray();

        $usuarios = array_map(static fn (array $e) => (int) $e['usuario_id'], $resumenes);
        sort($usuarios);
        // Sigue siendo exactamente una fila por destinatario (dirección + coordinadores).
        $this->assertSame([1, 2, 3], $usuarios, 'no debe duplicar filas de resumen al reejecutar');

        foreach ([1, 2, 3] as $usuarioId) {
            $count = $this->conn->table('recordatorios_enviados')
                ->where('tipo', 'resumen')
                ->where('programado_para', '2026-07-13')
                ->where('usuario_id', $usuarioId)
                ->countAllResults();
            $this->assertSame(1, $count, "usuario {$usuarioId} debe tener exactamente 1 fila de resumen");
        }
    }

    public function testResumenNoSeGeneraEnFechaFueraDeFrecuencia(): void
    {
        // 2026-07-14 es martes → NO corresponde a 'semanal'.
        $this->assertFalse(RecordatorioService::correspondeAFrecuencia('2026-07-14', 'semanal'));
        $this->correr('2026-07-14');

        $resumenes = $this->conn->table('recordatorios_enviados')
            ->where('tipo', 'resumen')
            ->where('programado_para', '2026-07-14')
            ->countAllResults();
        $this->assertSame(0, $resumenes);
    }

    // ── Solicitud de avances (paso 5b) ────────────────────────────────────────

    public function testSolicitudAvancesLlegaAResponsablesYCorresponsablesActivos(): void
    {
        // Acuerdo abierto: responsable 6 + corresponsable 1 (dirección, que NO es
        // responsable/corresponsable de ningún acuerdo abierto del seed → prueba
        // limpia de la vía corresponsable).
        $id = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-08-15', estado: 'en_proceso');
        $this->conn->table('acuerdo_corresponsables')->insert(['acuerdo_id' => $id, 'usuario_id' => 1]);

        // 2026-07-13 es lunes → corresponde a 'semanal'.
        $this->correr('2026-07-13');

        // Responsable (6) y corresponsable (1) reciben exactamente una solicitud (digest por usuario).
        $this->assertSame(1, $this->contarSolicitudes(6, '2026-07-13'));
        $this->assertSame(1, $this->contarSolicitudes(1, '2026-07-13'), 'el corresponsable debe recibir la solicitud');

        // El inactivo (7), corresponsable del acuerdo 7 del seed, NO recibe.
        $this->assertSame(0, $this->contarSolicitudes(7, '2026-07-13'), 'un usuario inactivo no recibe solicitudes');

        // Las filas son digest (acuerdo_id NULL) y salieron enviadas.
        $filas = $this->conn->table('recordatorios_enviados')
            ->where('tipo', 'solicitud_avance')
            ->where('programado_para', '2026-07-13')
            ->get()->getResultArray();
        $this->assertNotEmpty($filas);
        foreach ($filas as $f) {
            $this->assertNull($f['acuerdo_id']);
            $this->assertSame('enviado', $f['estado']);
        }
    }

    public function testSolicitudAvancesRespetaLaBanderaDeshabilitada(): void
    {
        $this->setSolicitudAvances(false);

        $this->correr('2026-07-13'); // lunes: correspondería a la frecuencia.

        $total = $this->conn->table('recordatorios_enviados')
            ->where('tipo', 'solicitud_avance')
            ->where('programado_para', '2026-07-13')
            ->countAllResults();
        $this->assertSame(0, $total, 'con la bandera deshabilitada no se envían solicitudes');
    }

    public function testSolicitudAvancesNoSeGeneraFueraDeFrecuencia(): void
    {
        // 2026-07-14 es martes → NO corresponde a 'semanal' (bandera default = true).
        $this->correr('2026-07-14');

        $total = $this->conn->table('recordatorios_enviados')
            ->where('tipo', 'solicitud_avance')
            ->where('programado_para', '2026-07-14')
            ->countAllResults();
        $this->assertSame(0, $total);
    }

    public function testSolicitudAvancesNoSeDuplicaAlReejecutarElMismoDia(): void
    {
        $this->correr('2026-07-13');
        $this->correr('2026-07-13'); // re-ejecución del MISMO día.

        // Responsable 5 (del seed, acuerdos abiertos) sigue con exactamente una fila.
        $this->assertSame(1, $this->contarSolicitudes(5, '2026-07-13'), 'no debe duplicar la solicitud al reejecutar');
    }

    // ── Vencidos (paso 1): marca en_proceso pasado, no toca concluido ─────────

    public function testMarcarVencidosSoloAfectaEnProcesoPasadoNoConcluido(): void
    {
        // Acuerdo en_proceso con fecha pasada → debe pasar a vencido.
        $idPasado = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-07-01', estado: 'en_proceso');
        // Acuerdo en_proceso futuro → intacto.
        $idFuturo = $this->crearAcuerdo(responsableId: 6, fechaCompromiso: '2026-07-30', estado: 'en_proceso');

        // Acuerdo 2 del seed: concluido, fecha 2026-07-05 (pasada) → NO debe tocarse.
        $this->correr('2026-07-10');

        $pasado = $this->conn->table('acuerdos')->where('id', $idPasado)->get()->getRowArray();
        $this->assertSame('vencido', $pasado['estado']);

        $futuro = $this->conn->table('acuerdos')->where('id', $idFuturo)->get()->getRowArray();
        $this->assertSame('en_proceso', $futuro['estado']);

        $concluido = $this->conn->table('acuerdos')->where('id', 2)->get()->getRowArray();
        $this->assertSame('concluido', $concluido['estado']);
    }

    // ── Calendario: orquesta CalendarSync para pendientes/error (intentos<3) ───

    public function testCalendarioSincronizaPendientesYErroresConIntentosMenoresA3(): void
    {
        // Seed: acuerdo 8 pendiente (intentos 0) y acuerdo 10 error (intentos 2) son candidatos;
        // el resto está sincronizado. Ambos intentos < 3.
        $this->correr('2026-07-14'); // martes: sin resumen para no mezclar

        $sinc = $this->calendar->sincronizados;
        sort($sinc);
        $this->assertSame([8, 10], $sinc);
    }
}
