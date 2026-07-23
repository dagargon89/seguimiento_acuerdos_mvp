<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Color de avatar por usuario (identidad visual). Columna nullable
 * `usuarios.avatar_color` que guarda una CLAVE de la paleta fija
 * (teal/blue/purple/green/amber/rose/cyan/slate); NULL = color por defecto
 * (teal). Editable por el propio usuario vía `PATCH /me`. El DDL fuente de
 * verdad (docs/03-datos/panel_acuerdos_ddl.sql) refleja esta columna;
 * `EsquemaEspejoTest` compara el esquema migrado contra ese DDL.
 */
class AgregarAvatarColorUsuarios extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('usuarios', [
            'avatar_color' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'area_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('usuarios', 'avatar_color');
    }
}
