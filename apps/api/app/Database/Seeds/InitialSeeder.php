<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * Puebla la BD desde el espejo db.json (regla №1 de CLAUDE.md), SIN transformación:
 * cada tabla del JSON → misma tabla SQL, cada campo → misma columna. La única
 * serialización permitida es arrays/objetos → JSON y booleanos → 0/1. Todo en una
 * transacción. La forma db.json↔DDL se verifica en scripts/verificar_espejo.mjs.
 */
class InitialSeeder extends Seeder
{
    /** Orden de inserción respetando las FKs. */
    private const TABLAS = [
        'areas', 'usuarios', 'reuniones', 'acuerdos', 'acuerdo_corresponsables',
        'avances', 'configuracion', 'recordatorios_enviados', 'google_sync',
        'usuario_google_tokens', 'auditoria',
    ];

    public function run(): void
    {
        $ruta = realpath(ROOTPATH . '../web/src/lib/mock/db.json');
        if ($ruta === false) {
            throw new RuntimeException('No se encontró apps/web/src/lib/mock/db.json (espejo del DDL).');
        }
        $db = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);

        $this->db->transException(true)->transStart();
        foreach (self::TABLAS as $tabla) {
            $filas = $db[$tabla] ?? [];
            if ($filas === []) {
                continue;
            }
            $this->db->table($tabla)->insertBatch(array_map([$this, 'serializar'], $filas));
        }
        $this->db->transComplete();
    }

    /** Serializa una fila: arrays/objetos → JSON, booleanos → 0/1, resto sin cambios. */
    private function serializar(array $fila): array
    {
        foreach ($fila as $col => $valor) {
            if (is_array($valor)) {
                $fila[$col] = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($valor)) {
                $fila[$col] = $valor ? 1 : 0;
            }
        }

        return $fila;
    }
}
