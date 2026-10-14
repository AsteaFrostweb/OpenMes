<?php

namespace Tests\Feature\Schedule\Finite;

use App\Enums\ScheduledOperationStatus;
use App\Models\Line;
use App\Models\ScheduledOperation;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\Schedule\CapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Line axis of the capacity view for orders the finite scheduler placed: they
 * load each line by their committed operations there, not by their whole time
 * window — which spans steps on other lines and the waits between them.
 */
class LineCapacityOperationsTest extends TestCase
{
    use RefreshDatabase;
    use SchedulesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchedule();
    }

    private function commit(WorkOrder $order, Workstation $station, string $start, string $end, ScheduledOperationStatus $status = ScheduledOperationStatus::Scheduled): void
    {
        ScheduledOperation::create([
            'work_order_id' => $order->id, 'step_number' => 10, 'workstation_id' => $station->id,
            'line_id' => $station->line_id, 'planned_start_at' => $this->at($start), 'planned_end_at' => $this->at($end),
            'status' => $status, 'required_operators' => 1, 'resource_source' => ScheduledOperation::SOURCE_AUTO,
        ]);
    }

    private function lineCell(Line $line): array
    {
        $grid = app(CapacityService::class)->lineCapacity($this->monday, $this->monday->copy()->endOfWeek(), 'week');
        $row = collect($grid['resources'])->firstWhere('id', $line->id);

        return $row['cells'][array_key_first($row['cells'])];
    }

    /** A three-day window on line A whose steps run 2 h on A and 1 h on B. */
    private function scheduledOrder(Line $a, Line $b): WorkOrder
    {
        $order = $this->order($a, [['estimated_duration_minutes' => 120], ['estimated_duration_minutes' => 60]], [
            'planned_start_at' => $this->at('2026-10-12 06:00'),
            'planned_end_at' => $this->at('2026-10-14 14:00'),
        ]);
        $this->commit($order, $this->station($a), '2026-10-12 06:00', '2026-10-12 08:00');
        $this->commit($order, $this->station($b), '2026-10-14 13:00', '2026-10-14 14:00');

        return $order;
    }

    public function test_scheduled_order_loads_each_line_by_its_operations(): void
    {
        $a = $this->line();
        $b = $this->line();
        $this->scheduledOrder($a, $b);

        $this->assertSame(2.0, $this->lineCell($a)['planned_h']);
        $this->assertSame(1.0, $this->lineCell($b)['planned_h']);
    }

    public function test_order_without_operations_still_loads_its_window(): void
    {
        $line = $this->line();
        $this->order($line, [['estimated_duration_minutes' => 60]], [
            'planned_start_at' => $this->at('2026-10-12 06:00'),
            'planned_end_at' => $this->at('2026-10-12 10:00'),
        ]);

        $this->assertSame(4.0, $this->lineCell($line)['planned_h']);
    }

    public function test_infeasible_rows_do_not_replace_the_window(): void
    {
        $line = $this->line();
        $order = $this->order($line, [['estimated_duration_minutes' => 60]], [
            'planned_start_at' => $this->at('2026-10-12 06:00'),
            'planned_end_at' => $this->at('2026-10-12 10:00'),
        ]);
        $this->commit($order, $this->station($line), '2026-10-12 06:00', '2026-10-12 07:00', ScheduledOperationStatus::InfeasibleMaterial);

        $this->assertSame(4.0, $this->lineCell($line)['planned_h']);
    }

    public function test_operations_count_as_standard_work_under_efficiency(): void
    {
        $line = $this->line(['planning_efficiency_pct' => 50]);
        $order = $this->order($line, [['estimated_duration_minutes' => 60]], [
            'planned_start_at' => $this->at('2026-10-12 06:00'),
            'planned_end_at' => $this->at('2026-10-12 08:00'),
        ]);
        // 1 h of standard work stretched to 2 h by 50 % efficiency.
        $this->commit($order, $this->station($line), '2026-10-12 06:00', '2026-10-12 08:00');

        $this->assertSame(1.0, $this->lineCell($line)['planned_h']);
    }

    public function test_cell_drill_down_reconciles_with_the_grid(): void
    {
        $a = $this->line();
        $b = $this->line();
        $order = $this->scheduledOrder($a, $b);

        $rows = app(CapacityService::class)->cellOrders($b->id, $this->monday, $this->monday->copy()->endOfWeek());

        $this->assertCount(1, $rows);
        $this->assertSame($order->id, $rows[0]['id']);
        $this->assertSame(1.0, $rows[0]['hours']);
    }
}
