<?php

namespace App\Policies;

use CodeIgniter\Database\BaseBuilder;

/**
 * Visibilidad server-side de acuerdos por rol (doc 04 §A01, SRS §2.2).
 * SIEMPRE aplicada en el backend — nunca confiar en el cliente.
 *
 * **Visibilidad ABIERTA (ADR-007, decisión del stakeholder 2026-07-10,
 * temporal/reversible):** los tres roles aprobados (`direccion`,
 * `coordinador`, `responsable`) ven TODOS los acuerdos — "trabajamos en
 * conjunto". Esto SOLO afecta LECTURA (listado, detalle, calendario,
 * recordatorios que derivan de aquí). NO afecta escritura/edición
 * (`AcuerdosController::puedeEditarEstructura`), avances
 * (`puedeRegistrarAvance`), concluir/reabrir (solo Dirección, regla
 * intocable) ni el resumen por área (`ResumenController`, que filtra por
 * `area_id` directamente y no usa esta clase). El rol `pendiente` (o
 * cualquier rol desconocido) sigue sin ver nada — ya bloqueado antes de
 * llegar aquí por el guard `cuenta_pendiente`, reforzado en profundidad
 * abajo.
 *
 * **Para revertir** (volver a la visibilidad por participación/área previa a
 * ADR-007): restaurar esta clase al commit anterior a ADR-007 (conserva las
 * ramas de filtro por rol: coordinador → `area_id` = su área O responsable O
 * corresponsable; responsable → responsable O corresponsable) y restaurar
 * los tests AU-01/AU-02/AU-03 originales en `AcuerdosLecturaTest` — ambos
 * viven en el historial de git. Ver ADR-007 para el detalle completo.
 */
final class VisibilidadAcuerdos
{
    /** Roles aprobados que, bajo ADR-007, ven todos los acuerdos sin filtro. */
    private const ROLES_VISIBILIDAD_ABIERTA = ['direccion', 'coordinador', 'responsable'];

    /**
     * Aplica el filtro de visibilidad al builder de listado (WHERE).
     *
     * ADR-007: para los roles aprobados no se aplica ningún filtro (visibilidad
     * abierta). Para `pendiente`/rol desconocido, defensa en profundidad: un
     * WHERE siempre falso (el guard de rol pendiente ya bloquea la ruta antes
     * de llegar aquí, pero si algún día se llamara sin esa guardia, no debe
     * filtrar nada).
     *
     * @param array<string, mixed> $actor Fila de `usuarios` (usuarioActual).
     */
    public static function aplicarAlListado(BaseBuilder $builder, array $actor): BaseBuilder
    {
        if (in_array($actor['rol'], self::ROLES_VISIBILIDAD_ABIERTA, true)) {
            return $builder;
        }

        // Defensa en profundidad: rol no aprobado (pendiente o desconocido) → nada visible.
        return $builder->where('1 = 0', null, false);
    }

    /**
     * Visibilidad del detalle. ADR-007: `true` para cualquiera de los tres
     * roles aprobados, sin importar área/participación; `false` para
     * pendiente/rol desconocido (defensa en profundidad, ver arriba).
     *
     * `$esCorresponsable` ya no participa en la decisión de visibilidad, pero
     * se conserva en la firma porque los controllers la calculan y la usan
     * también para los guards de ESCRITURA (`puedeRegistrarAvance`), que NO
     * cambian con esta regla.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $acuerdo Fila con al menos area_id, responsable_id.
     */
    public static function puedeVer(array $actor, array $acuerdo, bool $esCorresponsable): bool
    {
        return in_array($actor['rol'], self::ROLES_VISIBILIDAD_ABIERTA, true);
    }
}
