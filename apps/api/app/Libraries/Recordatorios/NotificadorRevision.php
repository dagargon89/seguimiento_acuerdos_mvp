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

    /** Aprobación → responsable + corresponsables activos. */
    public function avisarAprobacion(int $acuerdoId): void
    {
        $this->avisarResponsables($acuerdoId, static fn (PlantillaCorreo $p, array $ac, array $d) => $p->conclusionAprobada($ac, $d));
    }

    /** @param callable(PlantillaCorreo, array, array): array{asunto:string,html:string} $construir */
    private function avisarResponsables(int $acuerdoId, callable $construir): void
    {
        $db      = Database::connect();
        $acuerdo = $db->table('acuerdos')->select('id, accion, responsable_id')->where('id', $acuerdoId)->get()->getRowArray();
        if ($acuerdo === null) {
            return;
        }
        $destinatarios = $db->table('usuarios u')
            ->select('u.id, u.email, u.nombre')
            ->join('acuerdo_corresponsables ac', 'ac.usuario_id = u.id', 'left')
            ->groupStart()->where('u.id', (int) $acuerdo['responsable_id'])->orWhere('ac.acuerdo_id', $acuerdoId)->groupEnd()
            ->where('u.activo', 1)->groupBy('u.id')->get()->getResultArray();

        $plantilla = new PlantillaCorreo();
        $mailer    = service('mailer');
        foreach ($destinatarios as $dest) {
            try {
                $correo = $construir($plantilla, $acuerdo, $dest);
                $mailer->enviar((string) $dest['email'], $correo['asunto'], $correo['html']);
            } catch (Throwable $e) {
                log_message('error', 'Aviso de revisión a {email} falló: {msg}', ['email' => $dest['email'], 'msg' => $e->getMessage()]);
            }
        }
    }
}
