<?php

namespace App\Libraries\Recordatorios;

use App\Libraries\Correo\PlantillaCorreo;
use CodeIgniter\I18n\Time;
use Config\Database;
use Throwable;

/**
 * Notificación inmediata de asignación (ADR-010): al capturar un acuerdo se
 * envía un correo al responsable y a cada corresponsable activo, y cada envío
 * queda registrado en `recordatorios_enviados` con tipo `asignacion` (misma
 * trazabilidad que los recordatorios del job; la clave única
 * acuerdo+usuario+tipo+fecha deduplica reintentos del mismo día).
 *
 * Best-effort por destinatario: un fallo de Gmail marca la fila `fallido` con
 * el error y sigue con los demás — jamás propaga (la escritura del acuerdo ya
 * está confirmada). Con el binding Noop (sin credenciales/tests) registra el
 * envío con id sintético, igual que el job (RE-07).
 */
final class NotificadorAsignacion
{
    /** Envía y registra las notificaciones de asignación de un acuerdo recién capturado. */
    public function notificar(int $acuerdoId): void
    {
        $db = Database::connect();

        $acuerdo = $db->table('acuerdos')
            ->select('acuerdos.id, acuerdos.tema, acuerdos.accion, acuerdos.estado, acuerdos.fecha_compromiso, acuerdos.responsable_id, usuarios.nombre AS responsable_nombre')
            ->join('usuarios', 'usuarios.id = acuerdos.responsable_id', 'left')
            ->where('acuerdos.id', $acuerdoId)
            ->get()->getRowArray();

        if ($acuerdo === null) {
            return;
        }

        $plantilla = new PlantillaCorreo();
        $mailer    = service('mailer');
        $hoy       = Time::now()->toDateString();

        foreach ($this->destinatarios($acuerdoId, (int) $acuerdo['responsable_id']) as $dest) {
            $fila = [
                'acuerdo_id'      => $acuerdoId,
                'usuario_id'      => $dest['id'],
                'tipo'            => 'asignacion',
                'programado_para' => $hoy,
            ];

            try {
                $correo = $plantilla->asignacion($acuerdo, $dest, $dest['es_corresponsable']);
                $msgId  = $mailer->enviar($dest['email'], $correo['asunto'], $correo['html']);

                $fila += ['estado' => 'enviado', 'enviado_at' => Time::now()->toDateTimeString(), 'gmail_message_id' => $msgId];
            } catch (Throwable $e) {
                $fila += ['estado' => 'fallido', 'error' => mb_substr($e->getMessage(), 0, 65000)];
                log_message('error', 'Notificación de asignación falló para acuerdo {id} → {email}: {msg}', [
                    'id'    => $acuerdoId,
                    'email' => $dest['email'],
                    'msg'   => $e->getMessage(),
                ]);
            }

            try {
                $db->table('recordatorios_enviados')->insert($fila);
            } catch (Throwable $e) {
                // Duplicado por la clave única (reintento del mismo día) u otro
                // problema de BD: se registra y se continúa — nunca propaga.
                log_message('warning', 'No se registró la notificación de asignación (acuerdo {id}, usuario {uid}): {msg}', [
                    'id'  => $acuerdoId,
                    'uid' => $dest['id'],
                    'msg' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Responsable + corresponsables activos del acuerdo, marcando el rol de
     * cada quien para la plantilla.
     *
     * @return list<array{id: int, email: string, nombre: string, es_corresponsable: bool}>
     */
    private function destinatarios(int $acuerdoId, int $responsableId): array
    {
        // Mismo patrón que RecordatorioService::destinatariosDe (solo Query Builder).
        $filas = Database::connect()->table('usuarios u')
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
            'id'                => (int) $f['id'],
            'email'             => (string) $f['email'],
            'nombre'            => (string) $f['nombre'],
            'es_corresponsable' => (int) $f['id'] !== $responsableId,
        ], $filas);
    }
}
