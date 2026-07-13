<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Notificación de asignación (ADR-010): añade el 5º valor `asignacion` al
 * ENUM `recordatorios_enviados.tipo`. Al capturar un acuerdo se envía un
 * correo inmediato al responsable y corresponsables, y ese envío queda
 * registrado con este tipo (misma trazabilidad/dedup que los recordatorios
 * del job: clave única acuerdo+usuario+tipo+fecha). El DDL fuente de verdad
 * (docs/03-datos/panel_acuerdos_ddl.sql) refleja el ENUM de 5 valores;
 * `EsquemaEspejoTest` compara el esquema migrado contra ese DDL.
 */
class AgregarTipoAsignacion extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE recordatorios_enviados MODIFY tipo ENUM('previo','dia','vencido','resumen','asignacion') NOT NULL"
        );
    }

    public function down(): void
    {
        // Filas 'asignacion' no caben en el ENUM de 4 valores: se borran antes
        // de angostar la columna (histórico de notificaciones, no afecta acuerdos).
        $this->db->query("DELETE FROM recordatorios_enviados WHERE tipo = 'asignacion'");
        $this->db->query(
            "ALTER TABLE recordatorios_enviados MODIFY tipo ENUM('previo','dia','vencido','resumen') NOT NULL"
        );
    }
}
