<?php

namespace App\Extension\Contracts\Null;

use App\Extension\Contracts\WorkforceCapacityProvider;
use Carbon\CarbonInterface;

/**
 * Nothing records crews or skills, so nothing constrains a schedule by them:
 * the scheduler places operations on machine capacity alone, exactly as it
 * would if those tables existed and were empty.
 */
class NullWorkforceCapacityProvider implements WorkforceCapacityProvider
{
    public function availableOperators(?int $lineId, CarbonInterface $start, CarbonInterface $end): ?int
    {
        return null;
    }

    public function qualifiedOperators(?int $lineId, CarbonInterface $start, CarbonInterface $end, array $skillIds): ?int
    {
        return null;
    }
}
