<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Flujo de revisión de conclusión (spec 2026-07-29): flag `revision_estado`
 * independiente del enum `estado`. Migración ADITIVA y reversible. El DDL
 * (docs/03-datos/panel_acuerdos_ddl.sql) refleja las columnas; EsquemaEspejoTest
 * compara el esquema migrado contra ese DDL.
 */
class AgregarRevisionAcuerdos extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE acuerdos "
            . "ADD COLUMN revision_estado ENUM('sin_solicitud','pendiente','rechazada') NOT NULL DEFAULT 'sin_solicitud' AFTER concluido_at, "
            . 'ADD COLUMN revision_solicitada_por_id INT UNSIGNED NULL AFTER revision_estado, '
            . 'ADD COLUMN revision_solicitada_at DATETIME NULL AFTER revision_solicitada_por_id, '
            . 'ADD COLUMN revision_motivo_rechazo TEXT NULL AFTER revision_solicitada_at, '
            . 'ADD CONSTRAINT fk_acuerdos_revision_solicitante FOREIGN KEY (revision_solicitada_por_id) REFERENCES usuarios (id)'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE acuerdos DROP FOREIGN KEY fk_acuerdos_revision_solicitante');
        $this->db->query('ALTER TABLE acuerdos DROP COLUMN revision_motivo_rechazo, DROP COLUMN revision_solicitada_at, DROP COLUMN revision_solicitada_por_id, DROP COLUMN revision_estado');
    }
}
