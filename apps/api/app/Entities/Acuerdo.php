<?php

namespace App\Entities;

/**
 * Acuerdo completo (doc 05 §2.2) — types.ts `Acuerdo`. Se construye desde la
 * fila JOIN de `AcuerdoModel::builderConJoins()` + el array de corresponsables
 * resuelto aparte (whereIn agrupado, cero N+1) por el controller/policy.
 *
 * `estado` que llega aquí YA es el derivado en lectura (RF-05.2): la columna
 * `estado` de la fila viene sobrescrita por `estadoDerivadoExpr()` en el SELECT.
 */
final class Acuerdo
{
    public function __construct(
        public readonly int $id,
        public readonly Reunion $reunion,
        public readonly Area $area,
        public readonly ?string $tema,
        public readonly string $accion,
        public readonly UsuarioRef $responsable,
        /** @var UsuarioRef[] */
        public readonly array $corresponsables,
        public readonly UsuarioRef $capturadoPor,
        public readonly string $fechaCompromiso,
        public readonly string $estado,
        public readonly ?string $enlace,
        public readonly ?string $observaciones,
        /** @var int[]|null */
        public readonly ?array $recordatorioDias,
        public readonly ?UsuarioRef $concluidoPor,
        public readonly ?string $concluidoAt,
        public readonly string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $fila Fila con alias `reunion__*`, `area__*`,
     *                                    `responsable__*`, `capturado_por__*`, `concluido_por__*`
     *                                    producida por `AcuerdoModel::builderConJoins()`.
     * @param UsuarioRef[]         $corresponsables
     */
    public static function desdeFilaJoin(array $fila, array $corresponsables): self
    {
        $recordatorioDias = $fila['recordatorio_dias'] ?? null;
        if (is_string($recordatorioDias)) {
            $recordatorioDias = json_decode($recordatorioDias, true, 512, JSON_THROW_ON_ERROR);
        }

        return new self(
            (int) $fila['id'],
            Reunion::desdeFila(self::sub($fila, 'reunion')),
            Area::desdeFila(self::sub($fila, 'area')),
            $fila['tema'],
            (string) $fila['accion'],
            UsuarioRef::desdeFila(self::sub($fila, 'responsable')),
            $corresponsables,
            UsuarioRef::desdeFila(self::sub($fila, 'capturado_por')),
            (string) $fila['fecha_compromiso'],
            (string) $fila['estado'],
            $fila['enlace'],
            $fila['observaciones'],
            $recordatorioDias,
            $fila['concluido_por__id'] === null ? null : UsuarioRef::desdeFila(self::sub($fila, 'concluido_por')),
            $fila['concluido_at'],
            (string) $fila['created_at'],
            $fila['updated_at'],
        );
    }

    /** Extrae las columnas `prefijo__campo` de la fila JOIN a un array `{campo: valor}`. */
    private static function sub(array $fila, string $prefijo): array
    {
        $out    = [];
        $buscar = $prefijo . '__';
        foreach ($fila as $col => $val) {
            if (str_starts_with($col, $buscar)) {
                $out[substr($col, strlen($buscar))] = $val;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function aArray(): array
    {
        return [
            'id'                => $this->id,
            'reunion'           => $this->reunion->aArray(),
            'area'              => $this->area->aArray(),
            'tema'              => $this->tema,
            'accion'            => $this->accion,
            'responsable'       => $this->responsable->aArray(),
            'corresponsables'   => array_map(static fn (UsuarioRef $u) => $u->aArray(), $this->corresponsables),
            'capturado_por'     => $this->capturadoPor->aArray(),
            'fecha_compromiso'  => $this->fechaCompromiso,
            'estado'            => $this->estado,
            'enlace'            => $this->enlace,
            'observaciones'     => $this->observaciones,
            'recordatorio_dias' => $this->recordatorioDias,
            'concluido_por'     => $this->concluidoPor?->aArray(),
            'concluido_at'      => $this->concluidoAt,
            'created_at'        => $this->createdAt,
            'updated_at'        => $this->updatedAt,
        ];
    }
}
