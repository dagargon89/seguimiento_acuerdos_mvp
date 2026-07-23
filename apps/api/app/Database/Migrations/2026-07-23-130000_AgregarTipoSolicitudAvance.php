<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Solicitud de avances: añade el 6º valor `solicitud_avance` al ENUM
 * `recordatorios_enviados.tipo`. El job envía periódicamente (misma frecuencia
 * que el resumen) un correo a cada responsable/corresponsable con sus acuerdos
 * abiertos, pidiéndoles registrar el avance. El envío queda registrado con este
 * tipo y `acuerdo_id` NULL (digest por usuario, como el resumen). El envío está
 * condicionado por la bandera global `configuracion.recordatorios_default →
 * solicitud_avances_activa`. El DDL fuente de verdad
 * (docs/03-datos/panel_acuerdos_ddl.sql) refleja el ENUM de 6 valores;
 * `EsquemaEspejoTest` compara el esquema migrado contra ese DDL.
 */
class AgregarTipoSolicitudAvance extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE recordatorios_enviados MODIFY tipo ENUM('previo','dia','vencido','resumen','asignacion','solicitud_avance') NOT NULL"
        );
    }

    public function down(): void
    {
        // Filas 'solicitud_avance' no caben en el ENUM anterior: se borran antes
        // de angostar la columna (histórico de notificaciones, no afecta acuerdos).
        $this->db->query("DELETE FROM recordatorios_enviados WHERE tipo = 'solicitud_avance'");
        $this->db->query(
            "ALTER TABLE recordatorios_enviados MODIFY tipo ENUM('previo','dia','vencido','resumen','asignacion') NOT NULL"
        );
    }
}
