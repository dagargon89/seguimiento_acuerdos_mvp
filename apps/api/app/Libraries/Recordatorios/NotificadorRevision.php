<?php

namespace App\Libraries\Recordatorios;

use App\Libraries\Correo\PlantillaCorreo;
use Config\Database;
use Throwable;

/**
 * Correos inmediatos del flujo de revisión de conclusión (spec 2026-07-29).
 * Best-effort por destinatario; nunca propaga (la escritura ya está confirmada).
 * Sin registro en recordatorios_enviados (el enum de `tipo` no cubre revisión;
 * el rastro vive en `auditoria`).
 */
final class NotificadorRevision
{
    /** Solicitud → admins (rol direccion) + coordinación del área del acuerdo. */
    public function avisarSolicitud(int $acuerdoId, int $solicitanteId): void
    {
        $db      = Database::connect();
        $acuerdo = $db->table('acuerdos')->select('id, accion, area_id')->where('id', $acuerdoId)->get()->getRowArray();
        if ($acuerdo === null) {
            return;
        }
        $solicitante = $db->table('usuarios')->select('id, nombre')->where('id', $solicitanteId)->get()->getRowArray() ?? ['nombre' => 'Alguien'];

        $destinatarios = $db->table('usuarios')
            ->select('id, email, nombre')
            ->where('activo', 1)
            ->groupStart()
                ->where('rol', 'direccion')
                ->orGroupStart()->where('rol', 'coordinador')->where('area_id', (int) $acuerdo['area_id'])->groupEnd()
            ->groupEnd()
            ->get()->getResultArray();

        $plantilla = new PlantillaCorreo();
        $mailer    = service('mailer');
        foreach ($destinatarios as $dest) {
            try {
                $correo = $plantilla->solicitudConclusion($acuerdo, $dest, $solicitante);
                $mailer->enviar((string) $dest['email'], $correo['asunto'], $correo['html']);
            } catch (Throwable $e) {
                log_message('error', 'Aviso de solicitud a {email} falló: {msg}', ['email' => $dest['email'], 'msg' => $e->getMessage()]);
            }
        }
    }
}
