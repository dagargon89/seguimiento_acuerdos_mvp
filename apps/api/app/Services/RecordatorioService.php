<?php

namespace App\Services;

use App\Libraries\Correo\Mailer;
use App\Libraries\Correo\PlantillaCorreo;
use App\Libraries\Google\CalendarSync;
use App\Libraries\Recordatorios\Programador;
use App\Models\AcuerdoModel;
use App\Models\AuditoriaModel;
use App\Models\ConfiguracionModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeInterface;
use Throwable;

/**
 * Orquestador del job diario `recordatorios:procesar` (RF-05/RF-08/RF-11,
 * doc 02 §job). Espejo server-side de la lógica del mock
 * (`aplicarJobVencidos` + `programadosDe` + resumen) de
 * `apps/web/src/lib/api.mock.ts`.
 *
 * Construido contra las interfaces `Mailer`/`CalendarSync` para poder probarlo
 * con dobles SIN credenciales de Google. Reglas de CLAUDE.md respetadas:
 * transacción en la materialización multi-tabla; idempotencia para
 * recordatorios normales por la UNIQUE natural
 * `(acuerdo_id, usuario_id, tipo, programado_para)`; para el resumen
 * (`acuerdo_id` NULL) esa UNIQUE NO aplica — ver nota en
 * `procesarResumenPeriodico()`; `vencido` solo lo asigna el sistema; fechas en
 * TZ America/Ciudad_Juarez (la `$fecha` llega ya calculada por el comando);
 * Query Builder; auditoría de la corrida; un fallo de envío NO aborta el job.
 */
final class RecordatorioService
{
    private BaseConnection $db;
    private PlantillaCorreo $plantilla;

    public function __construct(
        private Mailer $mailer,
        private CalendarSync $calendarSync,
    ) {
        $this->db        = Database::connect();
        $this->plantilla = new PlantillaCorreo();
    }

    /**
     * Ejecuta la corrida para `$fecha` (día en TZ Juárez). Devuelve los
     * contadores. Re-ejecutable el mismo día sin duplicar (RE-04).
     */
    public function procesar(DateTimeInterface $fecha): ResumenCorrida
    {
        $hoy     = $fecha->format('Y-m-d');
        $resumen = new ResumenCorrida(fecha: $hoy);

        // 1. Marcar vencidos (RF-05.2): solo el sistema; no toca concluidos.
        $resumen->vencidosMarcados = $this->marcarVencidos($hoy);

        // 2 + 3. Materializar (transacción) y enviar los recordatorios del día.
        $this->materializarYEnviar($hoy, $resumen);

        // 4. Sincronizar calendario de los pendientes/erróneos (intentos < 3).
        $this->sincronizarCalendario($resumen);

        // 5. Resumen periódico (RF-11) si la fecha corresponde a la frecuencia.
        $this->procesarResumenPeriodico($hoy, $resumen);

        // 5b. Solicitud de avances a responsables/corresponsables, si está
        // habilitada globalmente y la fecha corresponde a la frecuencia.
        $this->procesarSolicitudAvances($hoy, $resumen);

        // 6. Auditar la corrida completa (usuario_id null = acción del sistema).
        (new AuditoriaModel())->registrar(
            null,
            'job_recordatorios',
            'sistema',
            null,
            ['job' => 'recordatorios:procesar'] + $resumen->aArray(),
        );

        return $resumen;
    }

    /** Paso 1: `en_proceso` con fecha_compromiso pasada → `vencido`. */
    private function marcarVencidos(string $hoy): int
    {
        $builder = $this->db->table('acuerdos');
        $builder->where('estado', 'en_proceso')
            ->where('fecha_compromiso <', $hoy)
            ->update(['estado' => 'vencido']);

        return $this->db->affectedRows();
    }

    /**
     * Pasos 2 y 3: por cada acuerdo ABIERTO (en_proceso + vencido), calcula los
     * recordatorios que "tocan" HOY con el `Programador`, inserta los que faltan
     * (transacción, idempotencia por UNIQUE natural) y los envía por Mailer.
     */
    private function materializarYEnviar(string $hoy, ResumenCorrida $resumen): void
    {
        $configGlobal = (new ConfiguracionModel())->recordatoriosDefault();

        $acuerdos = $this->db->table('acuerdos a')
            ->select('a.id, a.estado, a.fecha_compromiso, a.recordatorio_dias, a.responsable_id, a.area_id, a.tema, a.accion, r.nombre AS responsable_nombre')
            ->join('usuarios r', 'r.id = a.responsable_id', 'left')
            ->whereIn('a.estado', ['en_proceso', 'vencido'])
            ->get()->getResultArray();

        foreach ($acuerdos as $acuerdo) {
            $acuerdoId = (int) $acuerdo['id'];

            // Histórico de envíos de ESTE acuerdo (para que Programador no reofrezca los ya vistos).
            $enviados = $this->db->table('recordatorios_enviados')
                ->select('tipo, programado_para, estado')
                ->where('acuerdo_id', $acuerdoId)
                ->get()->getResultArray();

            $programados = Programador::programadosDe($acuerdo, $configGlobal, $enviados);

            // Solo los que caen EXACTAMENTE hoy.
            $delDia = array_filter(
                $programados,
                static fn ($p) => $p->programadoPara === $hoy,
            );
            if ($delDia === []) {
                continue;
            }

            $destinatarios = $this->destinatariosDe($acuerdoId, (int) $acuerdo['responsable_id']);

            foreach ($delDia as $prog) {
                foreach ($destinatarios as $usuario) {
                    $this->materializarUno(
                        $acuerdoId,
                        (int) $usuario['id'],
                        $prog->tipo,
                        $hoy,
                        $usuario,
                        $acuerdo,
                        $resumen,
                    );
                }
            }
        }
    }

    /**
     * Inserta (si no existe) y envía un recordatorio para un destinatario. La
     * inserción "reserva" la fila en transacción respetando la UNIQUE natural;
     * si ya existía (corrida previa el mismo día, RE-04), no hace nada.
     *
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $acuerdo
     */
    private function materializarUno(
        int $acuerdoId,
        int $usuarioId,
        string $tipo,
        string $hoy,
        array $usuario,
        array $acuerdo,
        ResumenCorrida $resumen,
    ): void {
        $reservado = $this->reservarEnvio($acuerdoId, $usuarioId, $tipo, $hoy);
        if (! $reservado) {
            return; // ya existía → idempotencia.
        }

        $resumen->materializados++;
        $correo = $this->plantilla->recordatorio($tipo, $acuerdo, $usuario);
        $this->enviarYMarcar(
            $reservado,
            (string) $usuario['email'],
            $correo['asunto'],
            $correo['html'],
            $resumen,
        );
    }

    /**
     * Reserva la fila de envío en transacción. Devuelve el id insertado, o null
     * si ya existía (check-then-act contra la UNIQUE natural
     * `(acuerdo_id, usuario_id, tipo, programado_para)` → idempotencia; esta
     * ruta SÍ tiene `acuerdo_id` no-NULL, así que la UNIQUE de BD también la
     * enforcea como respaldo ante una eventual carrera). Inserta con estado
     * `fallido`/enviado_at null como "pendiente de envío"; el paso de envío la
     * actualiza a `enviado` o deja `fallido` con error.
     */
    private function reservarEnvio(int $acuerdoId, ?int $usuarioId, string $tipo, string $programadoPara): ?int
    {
        // ¿Ya existe? (idempotencia). acuerdo_id null se maneja en la rama de resumen.
        $existe = $this->db->table('recordatorios_enviados')
            ->where('acuerdo_id', $acuerdoId)
            ->where('usuario_id', $usuarioId)
            ->where('tipo', $tipo)
            ->where('programado_para', $programadoPara)
            ->countAllResults() > 0;
        if ($existe) {
            return null;
        }

        $this->db->transStart();
        $this->db->table('recordatorios_enviados')->insert([
            'acuerdo_id'      => $acuerdoId,
            'usuario_id'      => $usuarioId,
            'tipo'            => $tipo,
            'programado_para' => $programadoPara,
            'estado'          => 'fallido', // provisional; el envío exitoso lo cambia.
            'enviado_at'      => null,
            'error'           => 'pendiente_de_envio',
        ]);
        $id = (int) $this->db->insertID();
        $this->db->transComplete();

        return $id;
    }

    /**
     * Envía el correo y actualiza la fila reservada. Un fallo del Mailer deja la
     * fila `fallido` con el mensaje de error y NO aborta el job (RE-07).
     */
    private function enviarYMarcar(int $filaId, string $para, string $asunto, string $html, ResumenCorrida $resumen): void
    {
        try {
            $messageId = $this->mailer->enviar($para, $asunto, $html);
            $this->db->table('recordatorios_enviados')->where('id', $filaId)->update([
                'estado'           => 'enviado',
                'enviado_at'       => date('Y-m-d H:i:s'),
                'gmail_message_id' => $messageId,
                'error'            => null,
            ]);
            $resumen->enviados++;
        } catch (Throwable $e) {
            $this->db->table('recordatorios_enviados')->where('id', $filaId)->update([
                'estado' => 'fallido',
                'error'  => $e->getMessage(),
            ]);
            $resumen->fallidos++;
            log_message('error', 'Fallo al enviar recordatorio (fila {id}): {msg}', ['id' => $filaId, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Destinatarios de un acuerdo (RF-08.3): responsable + todos los
     * corresponsables. Solo usuarios activos. Sin duplicados.
     *
     * @return list<array{id: int, email: string, nombre: string}>
     */
    private function destinatariosDe(int $acuerdoId, int $responsableId): array
    {
        $filas = $this->db->table('usuarios u')
            ->select('u.id, u.email, u.nombre')
            ->join('acuerdo_corresponsables ac', 'ac.usuario_id = u.id', 'left')
            ->groupStart()
            ->where('u.id', $responsableId)
            ->orWhere('ac.acuerdo_id', $acuerdoId)
            ->groupEnd()
            ->where('u.activo', 1)
            ->groupBy('u.id')
            ->get()->getResultArray();

        return array_map(static fn (array $f) => [
            'id'     => (int) $f['id'],
            'email'  => (string) $f['email'],
            'nombre' => (string) $f['nombre'],
        ], $filas);
    }

    /**
     * Paso 4: invoca CalendarSync para cada `google_sync` pendiente/erróneo con
     * intentos < 3. La lógica real (reintentos/idempotencia) es de S2.3; aquí
     * solo orquesta. Un fallo no aborta el job.
     */
    private function sincronizarCalendario(ResumenCorrida $resumen): void
    {
        $pendientes = $this->db->table('google_sync')
            ->select('acuerdo_id')
            ->whereIn('estado', ['pendiente', 'error'])
            ->where('intentos <', 3)
            ->get()->getResultArray();

        foreach ($pendientes as $fila) {
            try {
                $this->calendarSync->sincronizar((int) $fila['acuerdo_id']);
                $resumen->calendarioSincronizado++;
            } catch (Throwable $e) {
                $resumen->calendarioFallido++;
                log_message('error', 'Fallo al sincronizar calendario acuerdo {id}: {msg}', [
                    'id'  => $fila['acuerdo_id'],
                    'msg' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Paso 5 (RF-11): si `$hoy` corresponde a la frecuencia configurada,
     * materializa y envía un `tipo='resumen'` (acuerdo_id NULL) a Dirección
     * (ámbito general) y a cada coordinación (su área). Responsables puros NO
     * reciben resumen (regla del mock).
     *
     * IMPORTANTE — idempotencia real de esta rama: aquí `acuerdo_id` es
     * siempre NULL, y en MySQL una UNIQUE trata cada NULL como distinto de
     * cualquier otro (incluso de otro NULL), así que la UNIQUE natural
     * `(acuerdo_id, usuario_id, tipo, programado_para)` NO impide filas de
     * resumen duplicadas para el mismo usuario/día — a diferencia de
     * `reservarEnvio()` (recordatorios normales, `acuerdo_id` no-NULL), donde
     * esa UNIQUE sí es la garantía de BD. Aquí la única protección contra
     * duplicados es el check-then-act de abajo (`countAllResults() > 0` antes
     * de insertar), que es seguro porque el cron ejecuta el job con un solo
     * runner (sin corridas concurrentes el mismo día); NO sería seguro ante
     * ejecuciones concurrentes del job. No se agrega un lock nuevo: para el
     * MVP basta con documentar con precisión esta garantía más débil.
     */
    private function procesarResumenPeriodico(string $hoy, ResumenCorrida $resumen): void
    {
        $configGlobal = (new ConfiguracionModel())->recordatoriosDefault();
        $frecuencia   = (string) ($configGlobal['resumen_frecuencia'] ?? 'semanal');

        if (! self::correspondeAFrecuencia($hoy, $frecuencia)) {
            return;
        }

        $destinatarios = $this->db->table('usuarios')
            ->select('id, email, nombre, rol, area_id')
            ->whereIn('rol', ['direccion', 'coordinador'])
            ->where('activo', 1)
            ->get()->getResultArray();

        foreach ($destinatarios as $usuario) {
            $usuarioId = (int) $usuario['id'];

            $existe = $this->db->table('recordatorios_enviados')
                ->where('acuerdo_id', null)
                ->where('usuario_id', $usuarioId)
                ->where('tipo', 'resumen')
                ->where('programado_para', $hoy)
                ->countAllResults() > 0;
            if ($existe) {
                continue;
            }

            $this->db->transStart();
            $this->db->table('recordatorios_enviados')->insert([
                'acuerdo_id'      => null,
                'usuario_id'      => $usuarioId,
                'tipo'            => 'resumen',
                'programado_para' => $hoy,
                'estado'          => 'fallido',
                'enviado_at'      => null,
                'error'           => 'pendiente_de_envio',
            ]);
            $filaId = (int) $this->db->insertID();
            $this->db->transComplete();

            $resumen->materializados++;

            try {
                $correo    = $this->plantilla->resumen($usuario, $this->acuerdosDelAmbito($usuario));
                $messageId = $this->mailer->enviar(
                    (string) $usuario['email'],
                    $correo['asunto'],
                    $correo['html'],
                );
                $this->db->table('recordatorios_enviados')->where('id', $filaId)->update([
                    'estado'           => 'enviado',
                    'enviado_at'       => date('Y-m-d H:i:s'),
                    'gmail_message_id' => $messageId,
                    'error'            => null,
                ]);
                $resumen->resumenesEnviados++;
            } catch (Throwable $e) {
                $this->db->table('recordatorios_enviados')->where('id', $filaId)->update([
                    'estado' => 'fallido',
                    'error'  => $e->getMessage(),
                ]);
                $resumen->resumenesFallidos++;
                log_message('error', 'Fallo al enviar resumen a {mail}: {msg}', [
                    'mail' => $usuario['email'],
                    'msg'  => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Paso 5b: solicitud de avances. Si `solicitud_avances_activa` está
     * habilitada y `$hoy` corresponde a la frecuencia configurada, envía a cada
     * responsable/corresponsable ACTIVO de al menos un acuerdo abierto un correo
     * (`tipo='solicitud_avance'`, `acuerdo_id` NULL — digest por usuario) con la
     * lista de SUS acuerdos abiertos, pidiéndoles registrar el avance.
     *
     * Idempotencia: igual que el resumen, `acuerdo_id` es NULL, así que la
     * UNIQUE natural NO protege (MySQL trata cada NULL como distinto); la
     * garantía es el check-then-act de abajo + un único runner del cron.
     *
     * Sin N+1 (regla №9): un query trae los acuerdos abiertos, otro sus
     * corresponsables y otro los usuarios activos; el mapeo usuario→acuerdos se
     * arma en memoria.
     */
    private function procesarSolicitudAvances(string $hoy, ResumenCorrida $resumen): void
    {
        $configGlobal = (new ConfiguracionModel())->recordatoriosDefault();

        // Bandera global (opt-out): default habilitado si la clave no existe.
        if (! ($configGlobal['solicitud_avances_activa'] ?? true)) {
            return;
        }

        $frecuencia = (string) ($configGlobal['resumen_frecuencia'] ?? 'semanal');
        if (! self::correspondeAFrecuencia($hoy, $frecuencia)) {
            return;
        }

        // 1. Acuerdos abiertos con nombre del responsable (una sola consulta).
        $acuerdos = $this->db->table('acuerdos a')
            ->select('a.id, a.tema, a.accion, a.fecha_compromiso, a.estado, a.area_id, a.responsable_id, r.nombre AS responsable_nombre')
            ->join('usuarios r', 'r.id = a.responsable_id', 'left')
            ->whereIn('a.estado', ['en_proceso', 'vencido'])
            ->get()->getResultArray();

        if ($acuerdos === []) {
            return;
        }

        $acuerdoIds = array_map(static fn (array $a) => (int) $a['id'], $acuerdos);

        // 2. Corresponsables de esos acuerdos (una sola consulta, whereIn).
        $corresponsables = $this->db->table('acuerdo_corresponsables')
            ->select('acuerdo_id, usuario_id')
            ->whereIn('acuerdo_id', $acuerdoIds)
            ->get()->getResultArray();

        // 3. Mapa usuario_id → lista de SUS acuerdos abiertos (responsable o corresponsable).
        $acuerdoPorId  = [];
        $acuerdosDeUsuario = []; // usuario_id => list<acuerdo>
        foreach ($acuerdos as $a) {
            $aid                 = (int) $a['id'];
            $acuerdoPorId[$aid]  = $a;
            $resp                = (int) $a['responsable_id'];
            $acuerdosDeUsuario[$resp][$aid] = $a;
        }
        foreach ($corresponsables as $c) {
            $aid = (int) $c['acuerdo_id'];
            $uid = (int) $c['usuario_id'];
            if (isset($acuerdoPorId[$aid])) {
                $acuerdosDeUsuario[$uid][$aid] = $acuerdoPorId[$aid];
            }
        }

        if ($acuerdosDeUsuario === []) {
            return;
        }

        // 4. Datos de los destinatarios ACTIVOS (una sola consulta, whereIn).
        $destinatarios = $this->db->table('usuarios')
            ->select('id, email, nombre')
            ->whereIn('id', array_keys($acuerdosDeUsuario))
            ->where('activo', 1)
            ->get()->getResultArray();

        // Orden determinista por id (facilita pruebas y logs).
        usort($destinatarios, static fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);

        foreach ($destinatarios as $usuario) {
            $usuarioId = (int) $usuario['id'];

            $existe = $this->db->table('recordatorios_enviados')
                ->where('acuerdo_id', null)
                ->where('usuario_id', $usuarioId)
                ->where('tipo', 'solicitud_avance')
                ->where('programado_para', $hoy)
                ->countAllResults() > 0;
            if ($existe) {
                continue;
            }

            $this->db->transStart();
            $this->db->table('recordatorios_enviados')->insert([
                'acuerdo_id'      => null,
                'usuario_id'      => $usuarioId,
                'tipo'            => 'solicitud_avance',
                'programado_para' => $hoy,
                'estado'          => 'fallido',
                'enviado_at'      => null,
                'error'           => 'pendiente_de_envio',
            ]);
            $filaId = (int) $this->db->insertID();
            $this->db->transComplete();

            $resumen->materializados++;

            try {
                $correo    = $this->plantilla->solicitudAvances($usuario, array_values($acuerdosDeUsuario[$usuarioId]));
                $messageId = $this->mailer->enviar(
                    (string) $usuario['email'],
                    $correo['asunto'],
                    $correo['html'],
                );
                $this->db->table('recordatorios_enviados')->where('id', $filaId)->update([
                    'estado'           => 'enviado',
                    'enviado_at'       => date('Y-m-d H:i:s'),
                    'gmail_message_id' => $messageId,
                    'error'            => null,
                ]);
                $resumen->solicitudesEnviadas++;
            } catch (Throwable $e) {
                $this->db->table('recordatorios_enviados')->where('id', $filaId)->update([
                    'estado' => 'fallido',
                    'error'  => $e->getMessage(),
                ]);
                $resumen->solicitudesFallidas++;
                log_message('error', 'Fallo al enviar solicitud de avances a {mail}: {msg}', [
                    'mail' => $usuario['email'],
                    'msg'  => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * ¿La fecha `$hoy` (Y-m-d) dispara el resumen según la frecuencia?
     * Convención (no existía lógica previa en el repo): `semanal` = lunes;
     * `quincenal` = día 1 y 15 del mes; `mensual` = día 1. El seed trae un
     * resumen enviado el 2026-07-06 (lunes), consistente con `semanal`.
     */
    public static function correspondeAFrecuencia(string $hoy, string $frecuencia): bool
    {
        $fecha = new \DateTimeImmutable($hoy);
        $dia   = (int) $fecha->format('j');

        return match ($frecuencia) {
            'semanal'   => $fecha->format('N') === '1', // lunes
            'quincenal' => $dia === 1 || $dia === 15,
            'mensual'   => $dia === 1,
            default     => false,
        };
    }

    /**
     * Acuerdos abiertos (en_proceso + vencido) del ámbito del destinatario del
     * resumen (RF-11): Dirección ve TODOS; coordinación solo los de su área.
     * Responsables puros no llegan aquí (`procesarResumenPeriodico` ya los
     * excluye). Incluye el nombre del responsable para la plantilla.
     *
     * @param array<string, mixed> $usuario
     *
     * @return list<array<string, mixed>>
     */
    private function acuerdosDelAmbito(array $usuario): array
    {
        $builder = $this->db->table('acuerdos a')
            ->select('a.id, a.tema, a.accion, a.fecha_compromiso, a.estado, a.area_id, r.nombre AS responsable_nombre')
            ->join('usuarios r', 'r.id = a.responsable_id', 'left')
            ->whereIn('a.estado', ['en_proceso', 'vencido']);

        if ((string) $usuario['rol'] !== 'direccion') {
            $builder->where('a.area_id', (int) $usuario['area_id']);
        }

        return $builder->get()->getResultArray();
    }
}
