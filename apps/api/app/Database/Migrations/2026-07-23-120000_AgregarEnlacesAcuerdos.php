<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Múltiples enlaces de productos por acuerdo. Añade la columna JSON
 * `acuerdos.enlaces` (arreglo de URLs), replicando el patrón de
 * `recordatorio_dias`. Migración ADITIVA y reversible: la columna `enlace`
 * (una sola URL) se conserva como legado y se rellena `enlaces` a partir de
 * ella (cero pérdida de datos; el rollback solo elimina la columna nueva).
 *
 * El DDL fuente de verdad (docs/03-datos/panel_acuerdos_ddl.sql) refleja la
 * nueva columna; `EsquemaEspejoTest` compara el esquema migrado contra ese DDL.
 */
class AgregarEnlacesAcuerdos extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE acuerdos ADD COLUMN enlaces JSON NULL AFTER enlace');
        // Backfill: cada enlace único existente pasa a un arreglo de un elemento.
        $this->db->query(
            'UPDATE acuerdos SET enlaces = JSON_ARRAY(enlace) '
            . "WHERE enlace IS NOT NULL AND TRIM(enlace) <> ''"
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE acuerdos DROP COLUMN enlaces');
    }
}
