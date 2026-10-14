<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\GenerateScheduleRequest;
use App\Http\Requests\Schedule\ListScheduledOperationsRequest;
use App\Http\Requests\Schedule\OverrideScheduledOperationRequest;
use App\Http\Requests\Schedule\ScheduleSimulationActionRequest;
use App\Models\ScheduledOperation;
use App\Models\ScheduleSimulation;
use App\Services\Schedule\Finite\SchedulePresenter;
use App\Services\Schedule\Finite\ScheduleSimulationService;
use Illuminate\Http\JsonResponse;

/**
 * API surface of finite-capacity scheduling — the same service and JSON shape
 * the planner page uses. `GET schedule/operations` is the read contract for
 * consumers such as the completion forecast (#241) and schedule suggestions
 * (#39): deterministic placements and machine-readable infeasibility reasons,
 * without re-implementing any of the scheduling.
 */
class FiniteScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleSimulationService $simulations,
        private readonly SchedulePresenter $presenter,
    ) {}

    /** The committed operation plan. */
    public function operations(ListScheduledOperationsRequest $request): JsonResponse
    {
        $query = ScheduledOperation::committed()
            ->with(SchedulePresenter::RELATIONS)
            ->orderBy('work_order_id')
            ->orderBy('sequence');

        foreach (['work_order_id', 'workstation_id', 'line_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }
        if ($request->filled('from')) {
            $query->where(fn ($q) => $q->whereNull('planned_end_at')->orWhere('planned_end_at', '>=', $request->date('from')));
        }
        if ($request->filled('to')) {
            $query->where(fn ($q) => $q->whereNull('planned_start_at')->orWhere('planned_start_at', '<=', $request->date('to')));
        }
        if ($request->filled('status')) {
            $query->whereIn('status', $request->input('status'));
        }

        return response()->json([
            'data' => $query->limit(5000)->get()->map(fn ($op) => $this->presenter->operation($op))->values(),
        ]);
    }

    public function generate(GenerateScheduleRequest $request): JsonResponse
    {
        $simulation = $this->simulations->generate($request->toSchedulingRequest(), $request->user());

        return response()->json(['data' => $this->presenter->simulation($simulation)], 201);
    }

    public function show(ScheduleSimulation $simulation): JsonResponse
    {
        abort_unless(request()->user()?->hasAnyRole(['Admin', 'Supervisor']), 403);

        return response()->json(['data' => $this->presenter->simulation($simulation)]);
    }

    public function apply(ScheduleSimulationActionRequest $request, ScheduleSimulation $simulation): JsonResponse
    {
        try {
            $result = $this->simulations->apply($simulation, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true] + $result);
    }

    public function discard(ScheduleSimulationActionRequest $request, ScheduleSimulation $simulation): JsonResponse
    {
        try {
            $this->simulations->discard($simulation);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true]);
    }

    public function override(OverrideScheduledOperationRequest $request, ScheduledOperation $operation): JsonResponse
    {
        $updated = $this->simulations->overrideWorkstation($operation, $request->station(), $request->user());

        return response()->json(['data' => $this->presenter->operation($updated->load(SchedulePresenter::RELATIONS))]);
    }
}
