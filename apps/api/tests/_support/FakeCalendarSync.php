<?php

namespace Tests\Support;

use App\Libraries\Google\CalendarSync;

/**
 * Doble de CalendarSync para tests del job: cuenta las llamadas y registra los
 * ids de acuerdo sincronizados. No hace red.
 */
final class FakeCalendarSync implements CalendarSync
{
    /** @var list<int> */
    public array $sincronizados = [];

    public function sincronizar(int $acuerdoId): void
    {
        $this->sincronizados[] = $acuerdoId;
    }

    public function llamadas(): int
    {
        return count($this->sincronizados);
    }
}
