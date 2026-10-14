<?php

namespace Tests\Feature\Schedule\Finite;

use App\Enums\ScheduledOperationStatus;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\ScheduleChangeLog;
use App\Models\ScheduledOperation;
use App\Models\ScheduleSimulation;
use App\Models\User;
use App\Models\WorkstationType;
use App\Services\Schedule\Finite\ScheduleSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * What-if workflow: a generated draft changes nothing until it is applied;
 * applying goes through the planner's own update path; discarding leaves no
 * trace on the committed schedule.
 */
class ScheduleSimulationServiceTest extends TestCase
{
    use RefreshDatabase;
    use SchedulesFixtures;

    private User $user;

    private ScheduleSimulationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchedule();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->service = app(ScheduleSimulationService::class);
    }

    public function test_generating_a_draft_does_not_touch_the_committed_schedule(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 60], ['estimated_duration_minutes' => 30]]);
        $before = $order->fresh()->only(['line_id', 'due_date', 'planned_start_at', 'planned_end_at', 'updated_at']);

        $simulation = $this->service->generate($this->request(), $this->user);

        $this->assertSame(ScheduleSimulation::STATUS_DRAFT, $simulation->status);
        $this->assertSame(2, $simulation->operations()->count());
        $this->assertSame(0, ScheduledOperation::committed()->count());
        $this->assertEquals($before, $order->fresh()->only(['line_id', 'due_date', 'planned_start_at', 'planned_end_at', 'updated_at']));
        $this->assertSame(0, ScheduleChangeLog::count());
        $this->assertSame(2, $simulation->summary['counts']['scheduled']);
    }

    public function test_a_new_draft_replaces_the_previous_one(): void
    {
        $line = $this->line();
        $this->order($line, [['estimated_duration_minutes' => 60]]);

        $first = $this->service->generate($this->request(), $this->user);
        $second = $this->service->generate($this->request(), $this->user);

        $this->assertSame(ScheduleSimulation::STATUS_DISCARDED, $first->fresh()->status);
        $this->assertSame(0, $first->operations()->count());
        $this->assertTrue($second->is($this->service->currentDraft()));
    }

    public function test_applying_writes_the_minute_plan_through_the_planner(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 60], ['estimated_duration_minutes' => 30]]);
        $simulation = $this->service->generate($this->request(), $this->user);

        $result = $this->service->apply($simulation, $this->user);

        $order->refresh();
        $this->assertSame(1, $result['applied']);
        $this->assertEquals($this->at('2026-10-12 06:00'), $order->planned_start_at);
        $this->assertEquals($this->at('2026-10-12 07:30'), $order->planned_end_at);
        $this->assertSame(ScheduleSimulation::STATUS_APPLIED, $simulation->fresh()->status);
        $this->assertSame(2, ScheduledOperation::committed()->where('work_order_id', $order->id)->count());
        // Same audit trail as a drag on the board: change log (undo-able) …
        $this->assertSame(1, ScheduleChangeLog::where('work_order_id', $order->id)->where('action', 'reschedule')->count());
        // … and the work order's own audit log.
        $this->assertTrue(AuditLog::where('entity_type', \App\Models\WorkOrder::class)->where('entity_id', $order->id)->where('action', 'updated')->exists());
    }

    public function test_applying_assigns_auto_selected_stations_to_batch_steps(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $this->station($line, $type);
        $station = $this->station($line, $type);
        $blocker = $this->order($line, [['estimated_duration_minutes' => 480, 'workstation_type_id' => $type->id]], ['priority' => 9]);
        $order = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id]]);
        $batch = Batch::create(['work_order_id' => $order->id, 'batch_number' => 1, 'target_qty' => 10, 'status' => Batch::STATUS_PENDING]);
        $step = BatchStep::create(['batch_id' => $batch->id, 'step_number' => 10, 'name' => 'Step 1', 'status' => BatchStep::STATUS_READY, 'workstation_type_id' => $type->id]);

        $simulation = $this->service->generate($this->request(), $this->user);
        $this->service->apply($simulation, $this->user);

        $this->assertSame($station->id, $step->fresh()->workstation_id);
        $this->assertSame($this->user->id, $step->fresh()->assigned_by_id);
        $this->assertNotNull($blocker->fresh()->planned_start_at);
    }

    public function test_an_order_without_a_line_lands_on_the_line_carrying_most_of_its_work(): void
    {
        $saw = $this->line();
        $mill = $this->line();
        $sawStation = $this->station($saw);
        $millStation = $this->station($mill);
        // Starts with a short step on the saw line; the bulk runs on the mill line.
        $order = $this->order(null, [
            ['estimated_duration_minutes' => 15, 'workstation_id' => $sawStation->id, 'workstation_name' => $sawStation->name],
            ['estimated_duration_minutes' => 120, 'workstation_id' => $millStation->id, 'workstation_name' => $millStation->name],
            ['estimated_duration_minutes' => 60, 'workstation_id' => $millStation->id, 'workstation_name' => $millStation->name],
        ]);

        $result = $this->service->apply($this->service->generate($this->request(), $this->user), $this->user);

        $this->assertSame($mill->id, $order->fresh()->line_id);
        // The step on the other line is summarised, not listed one by one.
        $this->assertContains(__('Steps running on a workstation of another line than their work order: :count.', ['count' => 1]), $result['warnings']);
        $this->assertNotContains(
            __('Step ":step" uses workstation ":ws" from another line.', ['step' => 'Step 1', 'ws' => $sawStation->name]),
            $result['warnings'],
        );
    }

    public function test_infeasible_orders_keep_their_placement_but_their_reasons_are_committed(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $order = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id]]);
        $simulation = $this->service->generate($this->request(), $this->user);

        $result = $this->service->apply($simulation, $this->user);

        $this->assertSame(1, $result['skipped']);
        $this->assertNull($order->fresh()->planned_start_at);
        $committed = ScheduledOperation::committed()->where('work_order_id', $order->id)->first();
        $this->assertSame(ScheduledOperationStatus::InfeasibleNoEligibleWorkstation, $committed->status);
        $this->assertSame('no_eligible_workstation', $committed->reasons[0]['code']);
    }

    public function test_a_stale_draft_cannot_be_applied(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 60]]);
        $simulation = $this->service->generate($this->request(), $this->user);

        $this->travel(1)->minutes();
        $order->update(['description' => 'changed meanwhile']);

        $this->expectException(\DomainException::class);
        $this->service->apply($simulation, $this->user);
    }

    public function test_discarding_leaves_nothing_behind(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 60]]);
        $simulation = $this->service->generate($this->request(), $this->user);

        $this->service->discard($simulation);

        $this->assertSame(ScheduleSimulation::STATUS_DISCARDED, $simulation->fresh()->status);
        $this->assertSame(0, ScheduledOperation::count());
        $this->assertNull($order->fresh()->planned_start_at);
    }

    public function test_only_a_draft_can_be_applied_or_discarded(): void
    {
        $line = $this->line();
        $this->order($line, [['estimated_duration_minutes' => 60]]);
        $simulation = $this->service->generate($this->request(), $this->user);
        $this->service->apply($simulation, $this->user);

        $this->expectException(\DomainException::class);
        $this->service->discard($simulation->fresh());
    }

    public function test_committed_operations_are_worked_around_by_the_next_run(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $this->station($line, $type);
        $first = $this->order($line, [['estimated_duration_minutes' => 120, 'workstation_type_id' => $type->id]]);
        $this->service->apply($this->service->generate($this->request(), $this->user), $this->user);

        $second = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id]]);
        $simulation = $this->service->generate($this->request(), $this->user);

        $op = $simulation->operations()->where('work_order_id', $second->id)->first();
        $this->assertEquals($this->at('2026-10-12 08:00'), $op->planned_start_at);
        $this->assertNotNull($first->fresh()->planned_start_at);
    }

    public function test_manual_override_moves_the_operation_and_is_audited(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $auto = $this->station($line, $type);
        $other = $this->station($line, $type);
        $order = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id]]);
        $batch = Batch::create(['work_order_id' => $order->id, 'batch_number' => 1, 'target_qty' => 10, 'status' => Batch::STATUS_PENDING]);
        $step = BatchStep::create(['batch_id' => $batch->id, 'step_number' => 10, 'name' => 'Step 1', 'status' => BatchStep::STATUS_READY, 'workstation_type_id' => $type->id]);
        $this->service->apply($this->service->generate($this->request(), $this->user), $this->user);
        $op = ScheduledOperation::committed()->where('work_order_id', $order->id)->first();
        $this->assertSame($auto->id, $op->workstation_id);

        $this->service->overrideWorkstation($op, $other, $this->user);

        $op->refresh();
        $this->assertSame($other->id, $op->workstation_id);
        $this->assertSame(ScheduledOperation::SOURCE_MANUAL, $op->resource_source);
        $this->assertSame($other->id, $step->fresh()->workstation_id);
        $this->assertTrue(AuditLog::where('entity_type', ScheduledOperation::class)->where('entity_id', $op->id)->where('action', 'updated')->exists());
    }

    public function test_override_refuses_a_station_already_booked(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $this->station($line, $type);
        $this->station($line, $type);
        $a = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id]], ['priority' => 2]);
        $b = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id]], ['priority' => 1]);
        $simulation = $this->service->generate($this->request(), $this->user);
        $opA = $simulation->operations()->where('work_order_id', $a->id)->first();
        $opB = $simulation->operations()->where('work_order_id', $b->id)->first();

        $this->expectException(ValidationException::class);
        $this->service->overrideWorkstation($opB, $opA->workstation, $this->user);
    }

    public function test_override_refuses_a_station_under_maintenance_in_the_window(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $this->station($line, $type);
        $this->station($line, $type);
        $order = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id]]);
        $op = $this->service->generate($this->request(), $this->user)->operations()->where('work_order_id', $order->id)->first();
        $other = \App\Models\Workstation::where('workstation_type_id', $type->id)->where('id', '!=', $op->workstation_id)->first();
        \App\Models\MaintenanceEvent::create([
            'title' => 'PM', 'event_type' => \App\Models\MaintenanceEvent::TYPE_PLANNED, 'status' => \App\Models\MaintenanceEvent::STATUS_PENDING,
            'line_id' => $line->id, 'workstation_id' => $other->id,
            'scheduled_at' => $op->planned_start_at, 'scheduled_end_at' => $op->planned_end_at,
        ]);

        try {
            $this->service->overrideWorkstation($op, $other, $this->user);
            $this->fail('The override should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame(__('The workstation is not available for the whole window (shift or maintenance).'), $e->errors()['workstation_id'][0]);
        }
        $this->assertNotSame($other->id, $op->fresh()->workstation_id);
    }
}
