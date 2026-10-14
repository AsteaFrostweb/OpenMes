<?php

namespace Tests\Feature\Schedule\Finite;

use App\Enums\ScheduledOperationStatus;
use App\Models\MaintenanceEvent;
use App\Models\ScheduledOperation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Schedule\CapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Workstation axis of the capacity view: station calendar × line efficiency
 * against the committed operation plan.
 */
class WorkstationCapacityTest extends TestCase
{
    use RefreshDatabase;
    use SchedulesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchedule();
    }

    private function commit(WorkOrder $order, int $stationId, string $start, string $end, ScheduledOperationStatus $status = ScheduledOperationStatus::Scheduled): void
    {
        ScheduledOperation::create([
            'work_order_id' => $order->id, 'step_number' => 10, 'workstation_id' => $stationId,
            'line_id' => $order->line_id, 'planned_start_at' => $this->at($start), 'planned_end_at' => $this->at($end),
            'status' => $status, 'required_operators' => 1, 'resource_source' => ScheduledOperation::SOURCE_AUTO,
        ]);
    }

    private function cell(int $stationId): array
    {
        $grid = app(CapacityService::class)->workstationCapacity($this->monday, $this->monday->copy()->endOfWeek(), 'week');
        $row = collect($grid['resources'])->firstWhere('id', $stationId);

        return $row['cells'][array_key_first($row['cells'])];
    }

    public function test_committed_operations_load_their_station(): void
    {
        $line = $this->line();
        $station = $this->station($line);
        $idle = $this->station($line);
        $order = $this->order($line, [['estimated_duration_minutes' => 120]]);
        $this->commit($order, $station->id, '2026-10-12 06:00', '2026-10-12 08:00');
        // Infeasible rows and other stations do not count.
        $this->commit($order, $idle->id, '2026-10-12 06:00', '2026-10-12 08:00', ScheduledOperationStatus::InfeasibleMaterial);

        $this->assertSame(40.0, $this->cell($station->id)['available_h']);
        $this->assertSame(2.0, $this->cell($station->id)['planned_h']);
        $this->assertSame(5, $this->cell($station->id)['load_pct']);
        $this->assertSame(0.0, $this->cell($idle->id)['planned_h']);
    }

    public function test_station_maintenance_and_efficiency_apply(): void
    {
        $line = $this->line(['planning_efficiency_pct' => 50]);
        $station = $this->station($line);
        MaintenanceEvent::create([
            'title' => 'PM', 'event_type' => MaintenanceEvent::TYPE_PLANNED, 'status' => MaintenanceEvent::STATUS_PENDING,
            'line_id' => $line->id, 'workstation_id' => $station->id,
            'scheduled_at' => $this->at('2026-10-13 06:00'), 'scheduled_end_at' => $this->at('2026-10-13 14:00'),
        ]);
        $order = $this->order($line, [['estimated_duration_minutes' => 60]]);
        // 1 h of standard work stretched to 2 h by 50 % efficiency.
        $this->commit($order, $station->id, '2026-10-12 06:00', '2026-10-12 08:00');

        $cell = $this->cell($station->id);

        $this->assertSame(32.0, $cell['calendar_h']);
        $this->assertSame(16.0, $cell['available_h']);
        $this->assertSame(1.0, $cell['planned_h']);
    }

    public function test_capacity_page_and_api_serve_the_workstation_axis(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $station = $this->station($this->line());

        $this->actingAs($admin)->get('/admin/schedule/capacity?axis=workstation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('axis', 'workstation')->where('grid.resources.0.id', $station->id));

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/schedule/capacity?axis=workstation')
            ->assertOk()
            ->assertJsonPath('axis', 'workstation')
            ->assertJsonPath('grid.resources.0.id', $station->id);
    }
}
