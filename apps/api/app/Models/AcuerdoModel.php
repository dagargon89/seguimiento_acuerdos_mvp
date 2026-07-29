<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use Config\Database;

/**
 * Acuerdos (doc 03) — lecturas del listado y detalle. Query Builder siempre;
 * los joins evitan N+1 (reunión, área, responsable, capturado_por, concluido_por
 * se resuelven en el mismo SELECT; corresponsables se cargan aparte con UN
 * `whereIn` agrupado sobre los ids de la página — ver AcuerdosController).
 *
 * `estado` se lee crudo aquí; el estado DERIVADO ("vencido" en lectura para un
 * `en_proceso` con `fecha_compromiso` pasada, RF-05.2) lo calcula
 * `estadoDerivadoExpr()` para reutilizarse tanto en SELECT como en WHERE sin
 * escribir nunca en la fila (el job de la Etapa 2 la persistirá).
 */
class AcuerdoModel extends Model
{
    protected $table         = 'acuerdos';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $returnType    = 'array';
    protected $allowedFields = [
        'reunion_id', 'area_id', 'tema', 'accion', 'responsable_id', 'capturado_por_id',
        'fecha_compromiso', 'estado', 'enlace', 'enlaces', 'observaciones', 'recordatorio_dias',
        'concluido_por_id', 'concluido_at',
        'revision_estado', 'revision_solicitada_por_id', 'revision_solicitada_at', 'revision_motivo_rechazo',
    ];

    /**
     * Expresión SQL reutilizable del estado derivado en lectura (RF-05.2):
     * un `en_proceso` con `fecha_compromiso < hoy` se lee/filtra como `vencido`.
     * `$hoy` se inyecta ya calculado en PHP (TZ America/Ciudad_Juarez) para no
     * depender de la TZ del servidor de BD — SIEMPRE es una fecha `Y-m-d`
     * calculada por el servidor (`Time::now()`), nunca input del cliente; se
     * escapa igual vía `esc()` del conector (regla №7 de CLAUDE.md: nunca
     * concatenar variables sin escapar en SQL, aunque el valor sea confiable).
     */
    public static function estadoDerivadoExpr(string $hoy): string
    {
        $hoyEscapado = Database::connect()->escape($hoy);

        return "CASE WHEN acuerdos.estado = 'en_proceso' AND acuerdos.fecha_compromiso < {$hoyEscapado} "
            . 'THEN \'vencido\' ELSE acuerdos.estado END';
    }

    /**
     * Builder base del listado/detalle con joins de cero N+1 para las
     * referencias 1:1 (reunion, area, responsable, capturado_por, concluido_por).
     * No incluye corresponsables (N:M) — esos se resuelven aparte.
     */
    public function builderConJoins(string $hoy): BaseBuilder
    {
        $estadoExpr = self::estadoDerivadoExpr($hoy);

        /** @var BaseBuilder $builder */
        $builder = $this->builder();

        return $builder->select("
                acuerdos.id, acuerdos.reunion_id, acuerdos.area_id, acuerdos.tema, acuerdos.accion,
                acuerdos.responsable_id, acuerdos.capturado_por_id, acuerdos.fecha_compromiso,
                acuerdos.estado AS estado_real, ({$estadoExpr}) AS estado,
                acuerdos.enlace, acuerdos.enlaces, acuerdos.observaciones, acuerdos.recordatorio_dias,
                acuerdos.concluido_por_id, acuerdos.concluido_at,
                acuerdos.revision_estado, acuerdos.revision_solicitada_por_id, acuerdos.revision_solicitada_at, acuerdos.revision_motivo_rechazo,
                acuerdos.created_at, acuerdos.updated_at,
                reuniones.id AS reunion__id, reuniones.nombre AS reunion__nombre, reuniones.fecha AS reunion__fecha,
                areas.id AS area__id, areas.nombre AS area__nombre, areas.activa AS area__activa,
                resp.id AS responsable__id, resp.nombre AS responsable__nombre, resp.email AS responsable__email, resp.avatar_color AS responsable__avatar_color,
                cap.id AS capturado_por__id, cap.nombre AS capturado_por__nombre, cap.email AS capturado_por__email, cap.avatar_color AS capturado_por__avatar_color,
                conc.id AS concluido_por__id, conc.nombre AS concluido_por__nombre, conc.email AS concluido_por__email, conc.avatar_color AS concluido_por__avatar_color
            ", false)
            ->join('reuniones', 'reuniones.id = acuerdos.reunion_id', 'left')
            ->join('areas', 'areas.id = acuerdos.area_id', 'left')
            ->join('usuarios AS resp', 'resp.id = acuerdos.responsable_id', 'left')
            ->join('usuarios AS cap', 'cap.id = acuerdos.capturado_por_id', 'left')
            ->join('usuarios AS conc', 'conc.id = acuerdos.concluido_por_id', 'left');
    }
}
