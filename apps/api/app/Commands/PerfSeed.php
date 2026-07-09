<?php

namespace App\Commands;

use App\Database\Seeds\PerfSeeder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * S3.1 — Atajo de conveniencia para `PerfSeeder` (~5,000 acuerdos + 1 acuerdo
 * con 50 avances), usado por el script de carga k6 (tests/perf/k6-acuerdos.js,
 * ver tests/perf/README.md). NUNCA corre en `testing` (protege la BD SQLite
 * en memoria de PHPUnit) — solo pensado para una BD de desarrollo/carga.
 *
 *     php spark perf:seed
 *     php spark perf:seed --force   # omite la confirmación interactiva
 */
class PerfSeed extends BaseCommand
{
    protected $group       = 'Rendimiento';
    protected $name        = 'perf:seed';
    protected $description = 'Siembra ~5,000 acuerdos (+ 1 con 50 avances) para el script de carga k6. Solo BD de dev.';
    protected $usage       = 'perf:seed [--force]';
    protected $options     = [
        '--force' => 'Omite la confirmación interactiva.',
    ];

    public function run(array $params): int
    {
        if (ENVIRONMENT === 'testing') {
            CLI::error('perf:seed no corre en el entorno "testing" (protege la BD efímera de PHPUnit).');

            return EXIT_ERROR;
        }

        $grupo = Database::connect()->getDatabase();
        CLI::write("Entorno: {$this->coloredEnv()} · BD destino: {$grupo}", 'yellow');
        CLI::write('Esto añade ~5,000 filas a la tabla `acuerdos` (y 50 avances). No borra nada existente.', 'yellow');

        $force = (bool) (CLI::getOption('force') ?? ($params['force'] ?? false));
        if (! $force && CLI::prompt('¿Continuar?', ['y', 'n']) !== 'y') {
            CLI::write('Cancelado.', 'red');

            return EXIT_SUCCESS;
        }

        try {
            $seeder = Database::seeder();
            $seeder->call(PerfSeeder::class);
        } catch (Throwable $e) {
            CLI::error('perf:seed falló: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Listo. Usa el id del "acuerdo ancla" reportado arriba como ACUERDO_ID en k6.', 'green');

        return EXIT_SUCCESS;
    }

    private function coloredEnv(): string
    {
        return ENVIRONMENT;
    }
}
