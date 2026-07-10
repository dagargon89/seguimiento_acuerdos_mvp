<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\I18n\Time;
use Config\Services;
use DateTimeImmutable;
use Throwable;

/**
 * Job diario de recordatorios (S2.1, doc 02 §job). Se corre por cron:
 *
 *     php spark recordatorios:procesar [--fecha=YYYY-MM-DD]
 *
 * `--fecha` (opcional) fija el día para pruebas deterministas; por defecto es
 * HOY en TZ America/Ciudad_Juarez. Delega toda la lógica en RecordatorioService.
 * SIN credenciales de Google configuradas, el envío/sincronización reales NO
 * ocurren (bindings noop) — el job corre igual de punta a punta.
 */
class RecordatoriosProcesar extends BaseCommand
{
    protected $group       = 'Recordatorios';
    protected $name        = 'recordatorios:procesar';
    protected $description  = 'Marca vencidos, materializa y envía recordatorios del día, sincroniza calendario y envía el resumen periódico.';
    protected $usage       = 'recordatorios:procesar [--fecha=YYYY-MM-DD]';
    protected $options     = [
        '--fecha' => 'Fecha de la corrida (YYYY-MM-DD) en TZ America/Ciudad_Juarez. Default: hoy.',
    ];

    public function run(array $params): int
    {
        $fechaStr = $params['fecha'] ?? CLI::getOption('fecha');

        try {
            $fecha = $this->resolverFecha($fechaStr);
        } catch (Throwable $e) {
            CLI::error('Fecha inválida: use YYYY-MM-DD. ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Corriendo recordatorios:procesar para ' . $fecha->format('Y-m-d') . ' ...', 'yellow');

        try {
            $resumen = Services::recordatorioService(false)->procesar($fecha);
        } catch (Throwable $e) {
            CLI::error('El job falló: ' . $e->getMessage());
            log_message('critical', 'recordatorios:procesar falló: {msg}', ['msg' => $e->getMessage()]);

            return EXIT_ERROR;
        }

        foreach ($resumen->aArray() as $clave => $valor) {
            CLI::write('  ' . str_pad((string) $clave, 26) . ': ' . $valor, 'green');
        }
        CLI::write('Listo.', 'green');

        return EXIT_SUCCESS;
    }

    private function resolverFecha(?string $fechaStr): DateTimeImmutable
    {
        if ($fechaStr === null || $fechaStr === '' || $fechaStr === true) {
            // Hoy en TZ de la app (America/Ciudad_Juarez).
            return new DateTimeImmutable(Time::now()->format('Y-m-d'));
        }

        $fechaStr = (string) $fechaStr;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaStr) !== 1) {
            throw new \InvalidArgumentException('formato no reconocido: ' . $fechaStr);
        }

        return new DateTimeImmutable($fechaStr);
    }
}
