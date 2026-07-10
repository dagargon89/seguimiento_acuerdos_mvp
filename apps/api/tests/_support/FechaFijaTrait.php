<?php

namespace Tests\Support;

use CodeIgniter\I18n\Time;

/**
 * Congela "hoy" para tests cuyas aserciones asumen una fecha de referencia
 * fija (2026-07-09, TZ America/Ciudad_Juarez — la misma "hoy" documentada en
 * los comentarios de `db.json`/seed). Sin esto, el estado derivado
 * (`vencido`, RF-05.2, ver `AcuerdoModel::estadoDerivadoExpr()` y
 * `AcuerdosController::hoy()`) se calcula con `Time::now()` real y las
 * aserciones que comparan totales/estados "frontera" (p.ej. acuerdo id 4,
 * fecha_compromiso 2026-07-09) se rompen en cuanto el reloj del sistema
 * avanza más allá de esa fecha.
 *
 * Uso en la clase de test:
 *
 *   protected function setUp(): void
 *   {
 *       parent::setUp();
 *       $this->fijarFechaTest();
 *       // ... resto del setUp propio de la clase (mocks, etc.)
 *   }
 *
 *   protected function tearDown(): void
 *   {
 *       // ... resto del tearDown propio de la clase
 *       $this->resetFechaTest();
 *       parent::tearDown();
 *   }
 */
trait FechaFijaTrait
{
    /** "Hoy" congelado para los tests que asumen esta fecha en sus aserciones. */
    private const FECHA_FIJA_TEST = '2026-07-09 09:00:00';

    private const ZONA_FIJA_TEST = 'America/Ciudad_Juarez';

    /** Congela `Time::now()` en toda la app a 2026-07-09 09:00:00 America/Ciudad_Juarez. */
    protected function fijarFechaTest(): void
    {
        Time::setTestNow(self::FECHA_FIJA_TEST, self::ZONA_FIJA_TEST);
    }

    /** Restaura `Time::now()` al reloj real del sistema. */
    protected function resetFechaTest(): void
    {
        Time::setTestNow();
    }
}
