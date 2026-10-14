<?php

namespace App\Services\Schedule\Finite;

use Carbon\CarbonInterface;

/**
 * One routing step of one work order, as the scheduler needs to see it.
 *
 * Built by {@see OperationProjector} from the order's frozen process snapshot
 * (and its batch steps, once they exist) — never stored as routing data of its
 * own. Times are in minutes; `null` duration means the routing gives no
 * estimate, which the scheduler reports instead of guessing.
 */
final class OperationDemand
{
    /**
     * @param  list<int>  $requiredSkillIds
     * @param  array<string, string|null>  $attributes  changeover dimensions (product_type, product_category)
     */
    public function __construct(
        public readonly int $workOrderId,
        public readonly int $stepNumber,
        public readonly string $name,
        public readonly ?int $batchStepId,
        public readonly ?int $fixedWorkstationId,
        public readonly ?int $workstationTypeId,
        public readonly ?int $setupMinutes,
        public readonly ?int $runMinutes,
        public readonly int $requiredOperators,
        public readonly array $requiredSkillIds,
        public readonly array $attributes,
        // Already running: the scheduler pins it from now for what is left.
        public readonly ?CarbonInterface $startedAt = null,
    ) {}

    /** Total standard duration (setup + run), or null when nothing estimates it. */
    public function standardMinutes(): ?int
    {
        if ($this->setupMinutes === null && $this->runMinutes === null) {
            return null;
        }

        return (int) ($this->setupMinutes ?? 0) + (int) ($this->runMinutes ?? 0);
    }

    public function isStarted(): bool
    {
        return $this->startedAt !== null;
    }
}
