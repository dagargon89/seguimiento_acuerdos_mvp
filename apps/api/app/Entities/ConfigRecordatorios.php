<?php

namespace App\Entities;

/** Config global de recordatorios (doc 03 tabla `configuracion`, clave `recordatorios_default`). */
final class ConfigRecordatorios
{
    public function __construct(
        /** @var int[] */
        public readonly array $diasAntes,
        public readonly bool $diaCompromiso,
        public readonly int $vencidoCadaDias,
        public readonly int $vencidoMaxRepeticiones,
        public readonly string $resumenFrecuencia,
        public readonly bool $solicitudAvancesActiva,
        public readonly bool $invitacionesCalendarioActivas,
    ) {
    }

    /** @param array<string, mixed> $valor Decodificado de la columna JSON `configuracion.valor`. */
    public static function desdeValor(array $valor): self
    {
        return new self(
            array_map('intval', $valor['dias_antes']),
            (bool) $valor['dia_compromiso'],
            (int) $valor['vencido_cada_dias'],
            (int) $valor['vencido_max_repeticiones'],
            (string) $valor['resumen_frecuencia'],
            // Retrocompatible: los valores guardados antes de este campo lo omiten;
            // el default es habilitado (comportamiento previo a la opción).
            (bool) ($valor['solicitud_avances_activa'] ?? true),
            // Invitación nativa de Google Calendar al crear/actualizar el evento.
            // Default `false` (no enviar): el evento se sincroniza igual.
            (bool) ($valor['invitaciones_calendario_activas'] ?? false),
        );
    }

    /** @return array{dias_antes: int[], dia_compromiso: bool, vencido_cada_dias: int, vencido_max_repeticiones: int, resumen_frecuencia: string, solicitud_avances_activa: bool, invitaciones_calendario_activas: bool} */
    public function aArray(): array
    {
        return [
            'dias_antes'                      => $this->diasAntes,
            'dia_compromiso'                  => $this->diaCompromiso,
            'vencido_cada_dias'               => $this->vencidoCadaDias,
            'vencido_max_repeticiones'        => $this->vencidoMaxRepeticiones,
            'resumen_frecuencia'              => $this->resumenFrecuencia,
            'solicitud_avances_activa'        => $this->solicitudAvancesActiva,
            'invitaciones_calendario_activas' => $this->invitacionesCalendarioActivas,
        ];
    }
}
