<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Alta/actualización del usuario real de Dirección en el entorno de desarrollo
 * (S1.8 / ADR-002). NO se ejecuta en los tests (usan InitialSeeder) ni toca
 * db.json (espejo del DDL, regla №1 de CLAUDE.md) — es exclusivo del entorno real.
 *
 * `firebase_uid` queda NULL a propósito: se enlaza en el primer login por
 * email verificado (RF-01.3) — no se asume ni se inventa un UID.
 *
 * Uso: cd apps/api && php spark db:seed DevUsuariosSeeder
 */
class DevUsuariosSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'dgarcia@planjuarez.org';

        $existente = $this->db->table('usuarios')->where('email', $email)->get()->getRowArray();

        if ($existente !== null) {
            $this->db->table('usuarios')
                ->where('email', $email)
                ->update([
                    'rol'    => 'direccion',
                    'activo' => 1,
                ]);

            return;
        }

        $this->db->table('usuarios')->insert([
            'firebase_uid' => null,
            'nombre'       => 'David Garcia',
            'email'        => $email,
            'rol'          => 'direccion',
            'area_id'      => null,
            'activo'       => 1,
        ]);
    }
}
