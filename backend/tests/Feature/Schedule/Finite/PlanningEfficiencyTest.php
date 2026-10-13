<?php

namespace Tests\Feature\Schedule\Finite;

use App\Models\Line;
use App\Models\MaintenanceEvent;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Schedule\CapacityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Effective planning capacity: calendar capacity (shifts minus scheduled
 * maintenance) × the line's planning efficiency. Load is measured against the
 * effective figure; the calendar figure stays visible beside it.
 */
class PlanningEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 06:00'));
        $this->monday = Carbon::parse('2026-10-12');

        // One 8 h shift, Mon–Fri: 40 h of calendar capacity per week.
        Shift::create(['name' => 'Day', 'code' => 'D', 'start_time' => '06:00', 'end_time' => '14:00', 'days_of_week' => [1, 2, 3, 4, 5], 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cell(Line $line): array
    {
        $grid = app(CapacityService::class)->lineCapacity($this->monday, $this->monday->copy()->endOfWeek(), 'week');
        $row = collect($grid['resources'])->firstWhere('id', $line->id);

        return $row['cells'][array_key_first($row['cells'])] + ['efficiency' => $row['planning_efficiency_pct']];
    }

    public function test_default_efficiency_leaves_capacity_unchanged(): void
    {
        $line = Line::factory()->create();

        $cell = $this->cell($line->fresh());

        $this->assertSame(100.0, (float) $line->fresh()->planning_efficiency_pct);
        $this->assertSame(40.0, $cell['calendar_h']);
        $this->assertSame(40.0, $cell['available_h']);
        $this->assertSame(100.0, $cell['efficiency']);
    }

    public function test_efficiency_derates_capacity_after_maintenance(): void
    {
        $line = Line::factory()->create(['planning_efficiency_pct' => 82]);
        MaintenanceEvent::create([
            'title' => 'PM', 'event_type' => MaintenanceEvent::TYPE_PLANNED, 'status' => MaintenanceEvent::STATUS_PENDING,
            'line_id' => $line->id,
            'scheduled_at' => $this->monday->copy()->setTime(8, 0),
            'scheduled_end_at' => $this->monday->copy()->setTime(12, 0),
        ]);

        $cell = $this->cell($line);

        // (40 h − 4 h) × 82 % = 29.52 h
        $this->assertSame(36.0, $cell['calendar_h']);
        $this->assertSame(29.5, $cell['available_h']);
    }

    public function test_load_is_measured_against_effective_capacity(): void
    {
        $line = Line::factory()->create(['planning_efficiency_pct' => 50]);
        WorkOrder::factory()->create([
            'line_id' => $line->id,
            'status' => WorkOrder::STATUS_PENDING,
            'planned_start_at' => $this->monday->copy()->setTime(6, 0),
            'planned_end_at' => $this->monday->copy()->setTime(6, 0)->addHours(20),
            'due_date' => $this->monday,
        ]);

        $cell = $this->cell($line);

        // 20 h planned against 40 h × 50 % = 20 h effective → 100 %, not 50 %.
        $this->assertSame(20.0, $cell['available_h']);
        $this->assertSame(100, $cell['load_pct']);
    }

    public function test_admin_can_set_efficiency_on_a_line(): void
    {
        Role::findOrCreate('Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $line = Line::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/lines/{$line->id}", ['code' => $line->code, 'name' => $line->name, 'is_active' => true, 'planning_efficiency_pct' => 85])
            ->assertRedirect();

        $this->assertSame(85.0, (float) $line->fresh()->planning_efficiency_pct);
    }

    public function test_cleared_efficiency_means_no_derating(): void
    {
        Role::findOrCreate('Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $line = Line::factory()->create(['planning_efficiency_pct' => 70]);

        $this->actingAs($admin)
            ->put("/admin/lines/{$line->id}", ['code' => $line->code, 'name' => $line->name, 'is_active' => true, 'planning_efficiency_pct' => ''])
            ->assertRedirect();

        $this->assertSame(100.0, (float) $line->fresh()->planning_efficiency_pct);
    }

    public function test_efficiency_outside_range_is_rejected(): void
    {
        Role::findOrCreate('Admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $line = Line::factory()->create();

        foreach ([0, 120, 'abc'] as $bad) {
            $this->actingAs($admin)
                ->put("/admin/lines/{$line->id}", ['code' => $line->code, 'name' => $line->name, 'planning_efficiency_pct' => $bad])
                ->assertSessionHasErrors('planning_efficiency_pct');
        }
    }

    public function test_operator_cannot_change_efficiency(): void
    {
        Role::findOrCreate('Operator', 'web');
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $line = Line::factory()->create();

        $this->actingAs($operator)
            ->put("/admin/lines/{$line->id}", ['code' => $line->code, 'name' => $line->name, 'planning_efficiency_pct' => 50]);

        $this->assertSame(100.0, (float) $line->fresh()->planning_efficiency_pct);
    }

    public function test_crew_efficiency_is_read_from_the_provider(): void
    {
        $crew = new class
        {
            public int $id = 7;

            public string $name = 'Crew A';

            public string $code = 'A';

            public float $planning_efficiency_pct = 75;

            public $workers;

            public $breakWindows;

            public $lines;

            public function relationLoaded($r): bool
            {
                return $r === 'lines';
            }
        };
        $line = Line::factory()->create();
        $worker = new \App\Models\Worker(['name' => 'W']);
        $worker->id = 1;
        $worker->setRelation('workstation', new \App\Models\Workstation(['line_id' => $line->id]));
        $crew->workers = collect([$worker]);
        $crew->breakWindows = collect();
        $crew->lines = collect([$line]);

        $this->app->instance(\App\Extension\Contracts\WorkforceProvider::class, new class($crew) extends \App\Extension\Contracts\Null\NullWorkforceProvider
        {
            public function __construct(private object $crew) {}

            public function crewsForCapacity(): \Illuminate\Support\Collection
            {
                return collect([$this->crew]);
            }
        });

        $grid = app(CapacityService::class)->crewCapacity($this->monday, $this->monday->copy()->endOfWeek(), 'week');
        $row = collect($grid['resources'])->firstWhere('id', 7);
        $cell = $row['cells'][array_key_first($row['cells'])];

        $this->assertSame(40.0, $cell['calendar_h']);
        $this->assertSame(30.0, $cell['available_h']);
        $this->assertSame(75.0, $row['planning_efficiency_pct']);
    }
}
