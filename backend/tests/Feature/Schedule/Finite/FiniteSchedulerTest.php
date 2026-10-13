<?php

namespace Tests\Feature\Schedule\Finite;

use App\Enums\ScheduledOperationStatus;
use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\MaintenanceEvent;
use App\Models\Material;
use App\Models\ScheduledOperation;
use App\Models\WorkOrder;
use App\Models\WorkstationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deterministic finite-capacity scheduler: calendars, maintenance,
 * efficiency, committed load, precedence, equipment-class pools, material,
 * workforce and qualification constraints, lateness and repeatability.
 */
class FiniteSchedulerTest extends TestCase
{
    use RefreshDatabase;
    use SchedulesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchedule();
    }

    public function test_sequential_routing_is_placed_back_to_back(): void
    {
        $line = $this->line();
        $order = $this->order($line, [
            ['estimated_duration_minutes' => 60],
            ['estimated_duration_minutes' => 120],
            ['estimated_duration_minutes' => 60],
        ]);

        $ops = $this->opsFor($this->schedule(), $order);

        $this->assertCount(3, $ops);
        $this->assertEquals($this->at('2026-10-12 06:00'), $ops[0]['planned_start_at']);
        $this->assertEquals($this->at('2026-10-12 07:00'), $ops[1]['planned_start_at']);
        $this->assertEquals($this->at('2026-10-12 09:00'), $ops[2]['planned_start_at']);
        $this->assertEquals($this->at('2026-10-12 10:00'), $ops[2]['planned_end_at']);
        foreach ($ops as $op) {
            $this->assertSame(ScheduledOperationStatus::Scheduled, $op['status']);
            $this->assertSame(ScheduledOperation::SOURCE_LINE, $op['resource_source']);
        }
    }

    public function test_standard_times_drive_duration(): void
    {
        $line = $this->line();
        // 15 min setup + 10 units × 4.5 min = 60 min
        $order = $this->order($line, [['setup_time_minutes' => 15, 'run_time_per_unit_minutes' => 4.5, 'estimated_duration_minutes' => 999]]);

        $op = $this->opsFor($this->schedule(), $order)[0];

        $this->assertSame(60, $op['duration_minutes']);
        $this->assertSame(15, $op['setup_minutes']);
    }

    public function test_an_operation_pauses_across_non_working_time(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 600]]);

        $op = $this->opsFor($this->schedule(), $order)[0];

        // 8 h on Monday, the remaining 2 h on Tuesday morning.
        $this->assertEquals($this->at('2026-10-12 06:00'), $op['planned_start_at']);
        $this->assertEquals($this->at('2026-10-13 08:00'), $op['planned_end_at']);
    }

    public function test_maintenance_removes_capacity(): void
    {
        $line = $this->line();
        MaintenanceEvent::create([
            'title' => 'PM', 'event_type' => MaintenanceEvent::TYPE_PLANNED, 'status' => MaintenanceEvent::STATUS_PENDING,
            'line_id' => $line->id, 'scheduled_at' => $this->at('2026-10-12 06:00'), 'scheduled_end_at' => $this->at('2026-10-12 08:00'),
        ]);
        $order = $this->order($line, [['estimated_duration_minutes' => 60]]);

        $op = $this->opsFor($this->schedule(), $order)[0];

        $this->assertEquals($this->at('2026-10-12 08:00'), $op['planned_start_at']);
    }

    public function test_planning_efficiency_stretches_duration(): void
    {
        $line = $this->line(['planning_efficiency_pct' => 50]);
        $order = $this->order($line, [['estimated_duration_minutes' => 60]]);

        $op = $this->opsFor($this->schedule(), $order)[0];

        $this->assertSame(120, $op['duration_minutes']);
        $this->assertEquals($this->at('2026-10-12 08:00'), $op['planned_end_at']);
    }

    public function test_committed_minute_plans_are_worked_around(): void
    {
        $line = $this->line();
        $this->order($line, [['estimated_duration_minutes' => 240]], [
            'planned_start_at' => $this->at('2026-10-12 06:00'),
            'planned_end_at' => $this->at('2026-10-12 10:00'),
        ]);
        $order = $this->order($line, [['estimated_duration_minutes' => 60]]);

        $op = $this->opsFor($this->schedule(), $order)[0];

        $this->assertEquals($this->at('2026-10-12 10:00'), $op['planned_start_at']);
    }

    public function test_fixed_workstation_is_respected(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $free = $this->station($line, $type);
        $fixed = $this->station($line, $type);
        $order = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_id' => $fixed->id, 'workstation_type_id' => $type->id]]);

        $op = $this->opsFor($this->schedule(), $order)[0];

        $this->assertSame($fixed->id, $op['workstation_id']);
        $this->assertSame(ScheduledOperation::SOURCE_FIXED, $op['resource_source']);
        $this->assertNotSame($free->id, $op['workstation_id']);
    }

    public function test_equipment_class_pool_picks_the_free_eligible_station(): void
    {
        $line = $this->line();
        $cnc = WorkstationType::factory()->create();
        $other = WorkstationType::factory()->create();
        $cnc1 = $this->station($line, $cnc);
        $cnc2 = $this->station($line, $cnc);
        $this->station($line, $other); // free but incompatible

        $first = $this->order($line, [['estimated_duration_minutes' => 240, 'workstation_type_id' => $cnc->id]], ['priority' => 10]);
        $second = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $cnc->id]]);

        $result = $this->schedule();
        $a = $this->opsFor($result, $first)[0];
        $b = $this->opsFor($result, $second)[0];

        $this->assertSame($cnc1->id, $a['workstation_id']);
        $this->assertSame($cnc2->id, $b['workstation_id']);
        $this->assertSame(ScheduledOperation::SOURCE_AUTO, $b['resource_source']);
        $this->assertEquals($this->at('2026-10-12 06:00'), $b['planned_start_at']);
    }

    public function test_a_single_station_serializes_work(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $this->station($line, $type);
        $first = $this->order($line, [['estimated_duration_minutes' => 120, 'workstation_type_id' => $type->id]], ['priority' => 5]);
        $second = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id]]);

        $result = $this->schedule();

        $this->assertEquals($this->at('2026-10-12 08:00'), $this->opsFor($result, $second)[0]['planned_start_at']);
        $this->assertEquals($this->at('2026-10-12 06:00'), $this->opsFor($result, $first)[0]['planned_start_at']);
    }

    public function test_missing_equipment_blocks_the_step_and_its_successors(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create(); // no stations
        $order = $this->order($line, [
            ['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id],
            ['estimated_duration_minutes' => 60],
        ]);

        $ops = $this->opsFor($this->schedule(), $order);

        $this->assertSame(ScheduledOperationStatus::InfeasibleNoEligibleWorkstation, $ops[0]['status']);
        $this->assertSame('no_eligible_workstation', $ops[0]['reasons'][0]['code']);
        $this->assertSame(ScheduledOperationStatus::InfeasiblePredecessor, $ops[1]['status']);
        $this->assertNull($ops[1]['planned_start_at']);
    }

    public function test_unestimated_step_is_reported_not_guessed(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => null]]);

        $op = $this->opsFor($this->schedule(), $order)[0];

        $this->assertSame(ScheduledOperationStatus::InfeasibleUnestimated, $op['status']);
    }

    public function test_capacity_beyond_the_horizon_is_infeasible(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 60 * 8 * 3]]);

        $op = $this->opsFor($this->schedule(['end' => $this->monday->copy()->addDays(1)]), $order)[0];

        $this->assertSame(ScheduledOperationStatus::InfeasibleResourceCapacity, $op['status']);
        $this->assertSame('resource_capacity', $op['reasons'][0]['code']);
    }

    public function test_material_shortage_makes_the_order_infeasible_without_inventing_a_date(): void
    {
        $line = $this->line();
        $material = Material::factory()->create(['stock_quantity' => 0]);
        $order = $this->order($line, [['estimated_duration_minutes' => 60]]);
        $order->update(['process_snapshot' => array_merge($order->process_snapshot, ['bom' => [[
            'material_id' => $material->id, 'material_code' => $material->code, 'quantity_per_unit' => 1, 'scrap_percentage' => 0,
        ]]])]);

        $op = $this->opsFor($this->schedule(), $order)[0];

        $this->assertSame(ScheduledOperationStatus::InfeasibleMaterial, $op['status']);
        $this->assertSame('material', $op['reasons'][0]['code']);
        $this->assertArrayHasKey('material_ready_at', $op['reasons'][0]);
        $this->assertNull($op['reasons'][0]['material_ready_at']);
        $this->assertNull($op['planned_start_at']);

        $ignored = $this->opsFor($this->schedule(['respectMaterial' => false]), $order)[0];
        $this->assertSame(ScheduledOperationStatus::Scheduled, $ignored['status']);
    }

    public function test_operators_constrain_parallel_work(): void
    {
        $this->fakeWorkforce(operators: 2);
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $this->station($line, $type);
        $this->station($line, $type);
        $first = $this->order($line, [['estimated_duration_minutes' => 120, 'workstation_type_id' => $type->id, 'required_operators' => 2]], ['priority' => 5]);
        $second = $this->order($line, [['estimated_duration_minutes' => 60, 'workstation_type_id' => $type->id, 'required_operators' => 2]]);

        $result = $this->schedule();

        // A second machine is free, but both operators are on the first job.
        $this->assertEquals($this->at('2026-10-12 08:00'), $this->opsFor($result, $second)[0]['planned_start_at']);
        $this->assertEquals($this->at('2026-10-12 06:00'), $this->opsFor($result, $first)[0]['planned_start_at']);
    }

    public function test_too_few_operators_is_a_workforce_infeasibility(): void
    {
        $this->fakeWorkforce(operators: 1);
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 60, 'required_operators' => 3]]);

        $op = $this->opsFor($this->schedule(['end' => $this->monday->copy()->addDays(2)]), $order)[0];

        $this->assertSame(ScheduledOperationStatus::InfeasibleWorkforceCapacity, $op['status']);
    }

    public function test_untracked_workforce_never_constrains(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 60, 'required_operators' => 50]]);

        $this->assertSame(ScheduledOperationStatus::Scheduled, $this->opsFor($this->schedule(), $order)[0]['status']);
    }

    public function test_required_skills_constrain_only_when_configured(): void
    {
        $this->fakeWorkforce(operators: 5, qualified: 0);
        $line = $this->line();
        $skilled = $this->order($line, [['estimated_duration_minutes' => 60, 'required_skill_ids' => [7]]]);
        $plain = $this->order($line, [['estimated_duration_minutes' => 60]]);

        $result = $this->schedule(['end' => $this->monday->copy()->addDays(2)]);

        $this->assertSame(ScheduledOperationStatus::InfeasibleQualification, $this->opsFor($result, $skilled)[0]['status']);
        $this->assertSame(ScheduledOperationStatus::Scheduled, $this->opsFor($result, $plain)[0]['status']);
    }

    public function test_higher_priority_goes_first(): void
    {
        $line = $this->line();
        $low = $this->order($line, [['estimated_duration_minutes' => 60]], ['priority' => 1]);
        $high = $this->order($line, [['estimated_duration_minutes' => 60]], ['priority' => 9]);

        $result = $this->schedule();

        $this->assertEquals($this->at('2026-10-12 06:00'), $this->opsFor($result, $high)[0]['planned_start_at']);
        $this->assertEquals($this->at('2026-10-12 07:00'), $this->opsFor($result, $low)[0]['planned_start_at']);
    }

    public function test_finishing_after_the_due_date_is_flagged_late(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 600]], ['due_date' => $this->at('2026-10-12')]);

        $result = $this->schedule();
        $op = $this->opsFor($result, $order)[0];

        $this->assertSame(ScheduledOperationStatus::ScheduledLate, $op['status']);
        $this->assertContains('late', array_column($op['reasons'], 'code'));
        $this->assertTrue(collect($result['work_orders'])->firstWhere('work_order_id', $order->id)['late']);
    }

    public function test_finished_steps_drop_out_and_running_steps_are_pinned(): void
    {
        $line = $this->line();
        $order = $this->order($line, [
            ['estimated_duration_minutes' => 60],
            ['estimated_duration_minutes' => 120],
            ['estimated_duration_minutes' => 60],
        ], ['status' => WorkOrder::STATUS_IN_PROGRESS]);
        $batch = Batch::create(['work_order_id' => $order->id, 'batch_number' => 1, 'target_qty' => 10, 'status' => Batch::STATUS_IN_PROGRESS]);
        BatchStep::create(['batch_id' => $batch->id, 'step_number' => 10, 'name' => 'Step 1', 'status' => BatchStep::STATUS_DONE]);
        BatchStep::create(['batch_id' => $batch->id, 'step_number' => 20, 'name' => 'Step 2', 'status' => BatchStep::STATUS_IN_PROGRESS, 'started_at' => $this->at('2026-10-12 05:00')]);
        BatchStep::create(['batch_id' => $batch->id, 'step_number' => 30, 'name' => 'Step 3', 'status' => BatchStep::STATUS_PENDING]);

        $ops = $this->opsFor($this->schedule(), $order);

        $this->assertSame([20, 30], array_column($ops, 'step_number'));
        // 120 min estimate, 60 already elapsed → holds the line 06:00–07:00.
        $this->assertEquals($this->at('2026-10-12 07:00'), $ops[0]['planned_end_at']);
        $this->assertEquals($this->at('2026-10-12 07:00'), $ops[1]['planned_start_at']);
    }

    public function test_the_same_data_always_yields_the_same_plan(): void
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $this->station($line, $type);
        $this->station($line, $type);
        foreach (range(1, 6) as $i) {
            $this->order($line, [
                ['estimated_duration_minutes' => 30 * $i, 'workstation_type_id' => $type->id],
                ['estimated_duration_minutes' => 45],
            ], ['priority' => $i % 3]);
        }

        $strip = fn ($r) => array_map(fn ($op) => [$op['work_order_id'], $op['step_number'], $op['workstation_id'], (string) $op['planned_start_at']], $r['operations']);

        $this->assertSame($strip($this->schedule()), $strip($this->schedule()));
    }
}
