<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Esquema inicial del Panel de Acuerdos.
 *
 * Ejecuta un snapshot inmutable del DDL (docs/03-datos/panel_acuerdos_ddl.sql,
 * fuente de verdad) copiado en app/Database/sql/001_esquema_inicial.sql. El snapshot
 * contiene solo las 11 CREATE TABLE (sin CREATE DATABASE/USE/INSERT): los datos semilla
 * los inserta InitialSeeder desde db.json. La igualdad esquema↔DDL se verifica en
 * EsquemaEspejoTest.
 */
class CreateEsquemaInicial extends Migration
{
    /** Tablas en orden de dependencia (creación); el inverso sirve para el drop. */
    private const TABLAS = [
        'areas', 'usuarios', 'reuniones', 'acuerdos', 'acuerdo_corresponsables',
        'avances', 'configuracion', 'recordatorios_enviados', 'google_sync',
        'usuario_google_tokens', 'auditoria',
    ];

    public function up(): void
    {
        $ruta = dirname(__DIR__) . '/sql/001_esquema_inicial.sql';
        $sql  = @file_get_contents($ruta);
        if ($sql === false) {
            throw new RuntimeException("No se encontró el snapshot del DDL: {$ruta}");
        }

        foreach ($this->sentencias($sql) as $sentencia) {
            $this->db->query($sentencia);
        }
    }

    public function down(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLAS) as $tabla) {
            $this->db->query("DROP TABLE IF EXISTS `{$tabla}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Divide el script en sentencias ejecutables (una por CREATE TABLE). */
    private function sentencias(string $sql): array
    {
        return array_values(array_filter(array_map(
            static fn (string $s): string => trim($s),
            explode(';', $sql),
        ), static fn (string $s): bool => $s !== ''));
    }
}
