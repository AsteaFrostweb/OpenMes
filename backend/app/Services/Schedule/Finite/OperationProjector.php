<?php

namespace App\Services\Schedule\Finite;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\WorkOrder;

/**
 * Turns a work order's routing into the operations left to schedule.
 *
 * The routing is the order's frozen process snapshot — the same source batch
 * steps are materialized from — so planning and execution cannot disagree on
 * which steps exist, what they need or how long they take. Once batches exist
 * their steps add execution state: finished steps drop out, a running step is
 * pinned from its start, and a workstation already assigned to a batch step is
 * respected as fixed.
 *
 * Variant groups follow batch materialization: only the selected path is
 * projected ({@see WorkOrder::selectedVariantSteps()}).
 */
class OperationProjector
{
    /**
     * @return list<OperationDemand> in routing order
     */
    public function project(WorkOrder $workOrder): array
    {
        $steps = $workOrder->process_snapshot['steps'] ?? [];
        if ($steps === []) {
            return [];
        }

        usort($steps, fn ($a, $b) => (int) ($a['step_number'] ?? 0) <=> (int) ($b['step_number'] ?? 0));
        $chosen = $workOrder->selectedVariantSteps($steps);
        $batchSteps = $this->batchStepsByNumber($workOrder);
        $qty = max(0.0, (float) $workOrder->planned_qty);

        $attributes = [
            'product_type' => $workOrder->productType?->code,
            'product_category' => $workOrder->productType?->category,
        ];

        $out = [];
        foreach ($steps as $step) {
            $number = (int) ($step['step_number'] ?? 0);
            $group = $step['variant_group'] ?? null;
            if ($group !== null && isset($chosen[$group]) && $chosen[$group] !== $number) {
                continue;
            }

            $executions = $batchSteps[$number] ?? [];
            $open = array_values(array_filter(
                $executions,
                fn (BatchStep $s) => ! in_array($s->status, [BatchStep::STATUS_DONE, BatchStep::STATUS_SKIPPED], true),
            ));
            if ($executions !== [] && $open === []) {
                continue; // every batch has finished this step
            }

            $running = collect($open)->first(fn (BatchStep $s) => $s->status === BatchStep::STATUS_IN_PROGRESS);
            $assigned = collect($open)->first(fn (BatchStep $s) => $s->workstation_id !== null);
            $reference = $running ?? $open[0] ?? null;

            [$setup, $run] = $this->standardTimes($step, $qty);

            $out[] = new OperationDemand(
                workOrderId: $workOrder->id,
                stepNumber: $number,
                name: (string) ($step['name'] ?? __('Step :number', ['number' => $number])),
                batchStepId: $reference?->id,
                fixedWorkstationId: $this->intOrNull($assigned?->workstation_id ?? $step['workstation_id'] ?? null),
                workstationTypeId: $this->intOrNull($reference?->workstation_type_id ?? $step['workstation_type_id'] ?? null),
                setupMinutes: $setup,
                runMinutes: $run,
                requiredOperators: max(1, (int) ($step['required_operators'] ?? 1)),
                requiredSkillIds: array_values(array_map('intval', $step['required_skill_ids'] ?? [])),
                attributes: $attributes,
                startedAt: $running?->started_at,
            );
        }

        return $out;
    }

    /**
     * Setup and run minutes from the snapshot's standard times; failing those,
     * the step's overall estimate as run time with no separable setup.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function standardTimes(array $step, float $qty): array
    {
        $setup = $step['setup_time_minutes'] ?? null;
        $perUnit = $step['run_time_per_unit_minutes'] ?? null;

        if ($setup !== null || $perUnit !== null) {
            $setupMinutes = (int) round((float) ($setup ?? 0));
            $runMinutes = (int) ceil((float) ($perUnit ?? 0) * $qty);

            return $setupMinutes + $runMinutes > 0 ? [$setupMinutes, $runMinutes] : [null, null];
        }

        $estimate = (int) ($step['estimated_duration_minutes'] ?? 0);

        return $estimate > 0 ? [null, $estimate] : [null, null];
    }

    /**
     * Batch steps of the order's live batches, grouped by step number.
     *
     * @return array<int, list<BatchStep>>
     */
    private function batchStepsByNumber(WorkOrder $workOrder): array
    {
        $out = [];
        foreach ($workOrder->batches as $batch) {
            if ($batch->status === Batch::STATUS_CANCELLED) {
                continue;
            }
            foreach ($batch->steps as $step) {
                $out[(int) $step->step_number][] = $step;
            }
        }

        return $out;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
