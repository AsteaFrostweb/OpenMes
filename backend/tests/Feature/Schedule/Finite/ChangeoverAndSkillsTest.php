<?php

namespace Tests\Feature\Schedule\Finite;

use App\Models\ChangeoverRule;
use App\Models\ProcessSegment;
use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\TemplateStep;
use App\Models\User;
use App\Models\WorkstationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3 constraints: sequence-dependent changeover rules (and their CRUD),
 * and skill requirements frozen into the work-order snapshot.
 */
class ChangeoverAndSkillsTest extends TestCase
{
    use RefreshDatabase;
    use SchedulesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchedule();
        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Supervisor', 'web');
        Role::findOrCreate('Operator', 'web');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function family(string $category): ProductType
    {
        return ProductType::factory()->create(['category' => $category]);
    }

    /** Two jobs back to back on one station: [first, second] results. */
    private function twoJobs(ProductType $a, ProductType $b, int $setup = 10): array
    {
        $line = $this->line();
        $type = WorkstationType::factory()->create();
        $this->station($line, $type);
        $step = ['setup_time_minutes' => $setup, 'run_time_per_unit_minutes' => 5, 'workstation_type_id' => $type->id];
        $first = $this->order($line, [$step], ['priority' => 9, 'product_type_id' => $a->id]);
        $second = $this->order($line, [$step], ['priority' => 1, 'product_type_id' => $b->id]);
        $result = $this->schedule();

        return [$this->opsFor($result, $first)[0], $this->opsFor($result, $second)[0], $type];
    }

    public function test_without_rules_the_standard_setup_applies(): void
    {
        [$first, $second] = $this->twoJobs($this->family('A'), $this->family('B'));

        $this->assertSame(10, $first['setup_minutes']);
        $this->assertSame(10, $second['setup_minutes']);
        $this->assertSame(60, $second['duration_minutes']); // 10 + 10 × 5
    }

    public function test_a_family_change_rule_adds_its_setup_to_the_following_job(): void
    {
        ChangeoverRule::factory()->create(['from_value' => 'A', 'to_value' => 'B', 'setup_minutes' => 25]);
        ChangeoverRule::factory()->create(['from_value' => 'A', 'to_value' => 'A', 'setup_minutes' => 5]);

        [$first, $second] = $this->twoJobs($this->family('A'), $this->family('B'));

        // Nothing ran before the first job: standard setup.
        $this->assertSame(10, $first['setup_minutes']);
        $this->assertSame(25, $second['setup_minutes']);
        $this->assertSame(75, $second['duration_minutes']);
        $this->assertContains('changeover', array_column($second['reasons'], 'code'));
    }

    public function test_same_family_rule_shortens_setup(): void
    {
        ChangeoverRule::factory()->create(['from_value' => 'A', 'to_value' => 'A', 'setup_minutes' => 5]);

        [, $second] = $this->twoJobs($this->family('A'), $this->family('A'));

        $this->assertSame(5, $second['setup_minutes']);
    }

    public function test_the_most_specific_rule_wins(): void
    {
        ChangeoverRule::factory()->create(['from_value' => null, 'to_value' => 'B', 'setup_minutes' => 40]);
        $type = null;
        [, $second, $type] = $this->twoJobs($this->family('A'), $this->family('B'));
        ChangeoverRule::factory()->create(['from_value' => 'A', 'to_value' => 'B', 'setup_minutes' => 30, 'workstation_type_id' => $type->id]);

        $result = $this->schedule(['scope' => 'all']);
        $op = collect($result['operations'])->firstWhere('work_order_id', $second['work_order_id']);

        $this->assertSame(30, $op['setup_minutes']);
    }

    public function test_a_gap_is_not_used_when_it_would_lengthen_the_next_jobs_changeover(): void
    {
        $line = $this->line();
        $press = WorkstationType::factory()->create();
        $saw = WorkstationType::factory()->create();
        $this->station($line, $press);
        $this->station($line, $saw);
        // B job: saw 06–07, then press 07–07:30 — leaving the press idle 06–07.
        $b = $this->order($line, [
            ['estimated_duration_minutes' => 60, 'workstation_type_id' => $saw->id],
            ['estimated_duration_minutes' => 30, 'workstation_type_id' => $press->id],
        ], ['priority' => 9, 'product_type_id' => $this->family('B')->id]);
        // A job of 30 min fits the press gap — but A → B needs a 30 min changeover
        // the B job did not reserve, so it must go after B instead.
        $a = $this->order($line, [['estimated_duration_minutes' => 30, 'workstation_type_id' => $press->id]], ['priority' => 1, 'product_type_id' => $this->family('A')->id]);
        ChangeoverRule::factory()->create(['from_value' => 'A', 'to_value' => 'B', 'setup_minutes' => 30]);

        $result = $this->schedule();

        $this->assertEquals($this->at('2026-10-12 07:00'), $this->opsFor($result, $b)[1]['planned_start_at']);
        $this->assertEquals($this->at('2026-10-12 07:30'), $this->opsFor($result, $a)[0]['planned_start_at']);
    }

    public function test_a_gap_is_used_when_the_next_jobs_changeover_does_not_grow(): void
    {
        $line = $this->line();
        $press = WorkstationType::factory()->create();
        $saw = WorkstationType::factory()->create();
        $this->station($line, $press);
        $this->station($line, $saw);
        $b = $this->order($line, [
            ['estimated_duration_minutes' => 60, 'workstation_type_id' => $saw->id],
            ['estimated_duration_minutes' => 30, 'workstation_type_id' => $press->id],
        ], ['priority' => 9, 'product_type_id' => $this->family('B')->id]);
        $a = $this->order($line, [['estimated_duration_minutes' => 30, 'workstation_type_id' => $press->id]], ['priority' => 1, 'product_type_id' => $this->family('A')->id]);

        $result = $this->schedule();

        $this->assertEquals($this->at('2026-10-12 06:00'), $this->opsFor($result, $a)[0]['planned_start_at']);
    }

    public function test_inactive_and_deleted_rules_are_ignored(): void
    {
        ChangeoverRule::factory()->create(['from_value' => 'A', 'to_value' => 'B', 'setup_minutes' => 99, 'is_active' => false]);
        ChangeoverRule::factory()->create(['from_value' => 'A', 'to_value' => 'B', 'setup_minutes' => 77])->delete();

        [, $second] = $this->twoJobs($this->family('A'), $this->family('B'));

        $this->assertSame(10, $second['setup_minutes']);
    }

    public function test_product_type_code_rules_match_on_code(): void
    {
        $a = $this->family('X');
        $b = $this->family('X');
        ChangeoverRule::factory()->create([
            'attribute' => ChangeoverRule::ATTRIBUTE_PRODUCT_TYPE,
            'from_value' => strtolower($a->code), 'to_value' => $b->code, 'setup_minutes' => 45,
        ]);

        [, $second] = $this->twoJobs($a, $b);

        $this->assertSame(45, $second['setup_minutes']);
    }

    // ── skills frozen into the snapshot ─────────────────────────────────────

    public function test_segment_skills_are_frozen_into_the_snapshot(): void
    {
        $productType = ProductType::factory()->create();
        $template = ProcessTemplate::factory()->create(['product_type_id' => $productType->id]);
        $segment = ProcessSegment::factory()->create(['required_skill_ids' => [3, '8']]);
        TemplateStep::factory()->create(['process_template_id' => $template->id, 'step_number' => 1, 'process_segment_id' => $segment->id]);
        TemplateStep::factory()->create(['process_template_id' => $template->id, 'step_number' => 2]);

        $snapshot = $template->fresh()->toSnapshot();

        $this->assertSame([3, 8], $snapshot['steps'][0]['required_skill_ids']);
        $this->assertSame([], $snapshot['steps'][1]['required_skill_ids']);

        // Editing the segment after release does not change the frozen copy.
        $segment->update(['required_skill_ids' => [99]]);
        $this->assertSame([3, 8], $snapshot['steps'][0]['required_skill_ids']);
    }

    // ── CRUD ────────────────────────────────────────────────────────────────

    public function test_supervisor_with_the_schedule_tab_can_create_a_rule(): void
    {
        // Supervisors reach /admin/schedule only once an admin grants the tab.
        $supervisor = $this->user('Supervisor');
        $supervisor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('tab:schedule', 'web'));

        $this->actingAs($supervisor)
            ->post('/admin/schedule/changeover-rules', [
                'name' => 'A to B', 'attribute' => 'product_category', 'from_value' => ' A ', 'to_value' => 'B', 'setup_minutes' => 25, 'is_active' => true,
            ])
            ->assertRedirect('/admin/schedule/changeover-rules')
            ->assertSessionHas('success');

        $rule = ChangeoverRule::sole();
        $this->assertSame('A', $rule->from_value);
        $this->assertSame(25, $rule->setup_minutes);
    }

    public function test_admin_can_view_and_update_a_rule(): void
    {
        $admin = $this->user('Admin');
        $rule = ChangeoverRule::factory()->create();

        $this->actingAs($admin)->get('/admin/schedule/changeover-rules')->assertOk();
        $this->actingAs($admin)->get("/admin/schedule/changeover-rules/{$rule->id}/edit")->assertOk();
        $this->actingAs($admin)
            ->put("/admin/schedule/changeover-rules/{$rule->id}", ['name' => 'Renamed', 'attribute' => 'product_type', 'setup_minutes' => 12])
            ->assertRedirect();

        $this->assertSame('Renamed', $rule->fresh()->name);
        $this->assertNull($rule->fresh()->from_value);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $type = WorkstationType::factory()->create();
        $otherType = WorkstationType::factory()->create();
        $station = $this->station($this->line(), $otherType);

        $this->actingAs($this->user('Admin'))
            ->post('/admin/schedule/changeover-rules', [
                'name' => '', 'attribute' => 'colour', 'setup_minutes' => -1,
            ])
            ->assertSessionHasErrors(['name', 'attribute', 'setup_minutes']);

        $this->actingAs($this->user('Admin'))
            ->post('/admin/schedule/changeover-rules', [
                'name' => 'x', 'attribute' => 'product_type', 'setup_minutes' => 5,
                'workstation_type_id' => $type->id, 'workstation_id' => $station->id,
            ])
            ->assertSessionHasErrors(['workstation_id']);

        $this->assertSame(0, ChangeoverRule::count());
    }

    public function test_delete_is_soft(): void
    {
        $rule = ChangeoverRule::factory()->create();

        $this->actingAs($this->user('Admin'))->delete("/admin/schedule/changeover-rules/{$rule->id}")->assertRedirect();

        $this->assertSoftDeleted($rule);
    }

    public function test_guest_and_operator_are_refused(): void
    {
        $rule = ChangeoverRule::factory()->create();

        $this->get('/admin/schedule/changeover-rules')->assertRedirect('/login');
        $this->post('/admin/schedule/changeover-rules', ['name' => 'x'])->assertRedirect('/login');

        $operator = $this->user('Operator');
        $this->actingAs($operator)->get('/admin/schedule/changeover-rules')->assertForbidden();
        $this->actingAs($operator)->post('/admin/schedule/changeover-rules', [
            'name' => 'x', 'attribute' => 'product_type', 'setup_minutes' => 5,
        ])->assertForbidden();
        $this->actingAs($operator)->delete("/admin/schedule/changeover-rules/{$rule->id}")->assertForbidden();
        $this->assertSame(1, ChangeoverRule::count());
    }
}
