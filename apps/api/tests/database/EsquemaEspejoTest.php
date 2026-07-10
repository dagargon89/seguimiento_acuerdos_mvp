<?php

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Verifica que el esquema producido por la migración (snapshot del DDL) sea idéntico
 * al DDL fuente de verdad (docs/03-datos/panel_acuerdos_ddl.sql). Para no comparar la
 * migración contra sí misma, aplica el DDL ORIGINAL a una BD de referencia efímera y
 * confronta columna por columna (nombre, tipo con enum/longitud, nullabilidad, default)
 * y el número de FKs por tabla.
 *
 * @group database
 *
 * @internal
 */
final class EsquemaEspejoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';

    private const TABLAS = [
        'areas', 'usuarios', 'reuniones', 'acuerdos', 'acuerdo_corresponsables',
        'avances', 'configuracion', 'recordatorios_enviados', 'google_sync',
        'usuario_google_tokens', 'auditoria',
    ];

    private const REF = 'panel_acuerdos_ddl_ref';

    private string $migrada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrada = $this->db->getDatabase();
        $this->construirReferenciaDesdeDDL();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP DATABASE IF EXISTS ' . self::REF);
        $this->db->query('USE `' . $this->migrada . '`');
        parent::tearDown();
    }

    public function testEsquemaMigradoIgualAlDDL(): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->assertSame(
                $this->columnas($this->migrada, $tabla),
                $this->columnas(self::REF, $tabla),
                "Las columnas de '{$tabla}' difieren entre la migración y el DDL.",
            );
            $this->assertSame(
                $this->numFks($this->migrada, $tabla),
                $this->numFks(self::REF, $tabla),
                "El número de FKs de '{$tabla}' difiere entre la migración y el DDL.",
            );
        }
    }

    private function construirReferenciaDesdeDDL(): void
    {
        $ddl = (string) file_get_contents(ROOTPATH . '../../docs/03-datos/panel_acuerdos_ddl.sql');

        $this->db->query('DROP DATABASE IF EXISTS ' . self::REF);
        $this->db->query('CREATE DATABASE ' . self::REF . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->db->query('USE ' . self::REF);

        foreach (explode(';', $ddl) as $sentencia) {
            $s = trim($sentencia);
            if ($s === '' || preg_match('/^(CREATE DATABASE|USE|SET NAMES)\b/i', $s)) {
                continue;
            }
            $this->db->query($s);
        }

        $this->db->query('USE `' . $this->migrada . '`');
    }

    private function columnas(string $schema, string $tabla): array
    {
        return $this->db->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$schema, $tabla],
        )->getResultArray();
    }

    private function numFks(string $schema, string $tabla): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$schema, $tabla],
        )->getRow()->n;
    }
}
