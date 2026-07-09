<?php

namespace App\Policies;

use CodeIgniter\Database\BaseBuilder;

/**
 * Visibilidad server-side de acuerdos por rol (doc 04 §A01, SRS §2.2).
 * SIEMPRE aplicada en el backend — nunca confiar en el cliente.
 *
 * - direccion: ve todo.
 * - coordinador: `area_id` = su área, O es responsable, O es corresponsable.
 * - responsable: es responsable, O es corresponsable.
 *
 * "Es corresponsable" = EXISTS en `acuerdo_corresponsables`.
 */
final class VisibilidadAcuerdos
{
    /**
     * Aplica el filtro de visibilidad al builder de listado (WHERE), sin N+1:
     * usa un subquery EXISTS correlacionado en vez de cargar corresponsables
     * fila por fila.
     *
     * @param array<string, mixed> $actor Fila de `usuarios` (usuarioActual).
     */
    public static function aplicarAlListado(BaseBuilder $builder, array $actor): BaseBuilder
    {
        $rol = $actor['rol'];

        if ($rol === 'direccion') {
            return $builder;
        }

        $actorId = (int) $actor['id'];
        $existsCorresponsable = "EXISTS (
            SELECT 1 FROM acuerdo_corresponsables ac
            WHERE ac.acuerdo_id = acuerdos.id AND ac.usuario_id = {$actorId}
        )";

        if ($rol === 'coordinador') {
            $areaId = (int) $actor['area_id'];

            return $builder->groupStart()
                ->where('acuerdos.area_id', $areaId)
                ->orWhere('acuerdos.responsable_id', $actorId)
                ->orWhere($existsCorresponsable, null, false)
                ->groupEnd();
        }

        // responsable
        return $builder->groupStart()
            ->where('acuerdos.responsable_id', $actorId)
            ->orWhere($existsCorresponsable, null, false)
            ->groupEnd();
    }

    /**
     * Visibilidad del detalle (misma regla, evaluada en PHP sobre una fila ya
     * cargada). `$esCorresponsable` se resuelve con una query puntual
     * (EXISTS) en el controller — no hay N+1 posible en un solo detalle.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $acuerdo Fila con al menos area_id, responsable_id.
     */
    public static function puedeVer(array $actor, array $acuerdo, bool $esCorresponsable): bool
    {
        if ($actor['rol'] === 'direccion') {
            return true;
        }

        $esParticipante = ((int) $acuerdo['responsable_id']) === (int) $actor['id'] || $esCorresponsable;

        if ($esParticipante) {
            return true;
        }

        return $actor['rol'] === 'coordinador' && ((int) $acuerdo['area_id']) === (int) $actor['area_id'];
    }
}
