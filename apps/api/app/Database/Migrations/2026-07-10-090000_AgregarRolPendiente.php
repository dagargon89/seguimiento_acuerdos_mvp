<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Autorregistro (ADR-006, "Registro A"): añade el 4º valor `pendiente` al ENUM
 * `usuarios.rol`. Un usuario autorregistrado vía `POST /registro` nace con este
 * rol — sin acceso funcional — hasta que Dirección le asigna uno de los tres
 * roles operativos (`PATCH /usuarios/{id}`). El DDL fuente de verdad
 * (docs/03-datos/panel_acuerdos_ddl.sql) ya refleja el ENUM de 4 valores;
 * `EsquemaEspejoTest` compara el esquema migrado (migraciones 1+2) contra ese
 * DDL actualizado.
 */
class AgregarRolPendiente extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE usuarios MODIFY rol ENUM('direccion','coordinador','responsable','pendiente') NOT NULL"
        );
    }

    public function down(): void
    {
        // Filas con rol='pendiente' no caben en el ENUM de 3 valores: se borran
        // (junto con su auditoría, por la FK) antes de angostar la columna.
        // Aceptable — down() de esta migración es una operación de
        // mantenimiento/rollback explícito (o el regress(0) de
        // DatabaseTestTrait en tests, donde la tabla completa se dropea acto
        // seguido); revertir con cuentas pendientes vivas en producción implica
        // asumir la pérdida de esas filas o resolverlas manualmente antes.
        $this->db->query(
            "DELETE FROM auditoria WHERE usuario_id IN (SELECT id FROM usuarios WHERE rol = 'pendiente')"
        );
        $this->db->query("DELETE FROM usuarios WHERE rol = 'pendiente'");
        $this->db->query(
            "ALTER TABLE usuarios MODIFY rol ENUM('direccion','coordinador','responsable') NOT NULL"
        );
    }
}
