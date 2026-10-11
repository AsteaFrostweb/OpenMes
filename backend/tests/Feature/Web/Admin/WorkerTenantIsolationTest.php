<?php

namespace Tests\Feature\Web\Admin;

use App\Models\EmployeeActivity;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Workers belong to a tenant: a user sees, opens and changes only their own
 * tenant's workers, and a worker someone creates lands in that person's tenant.
 */
class WorkerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $adminA;

    private Worker $workerA;

    private Worker $workerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenantA = Tenant::create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::create(['name' => 'Tenant B']);

        $this->adminA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->adminA->assignRole('Admin');

        $this->workerA = Worker::factory()->create(['code' => 'W-A', 'tenant_id' => $this->tenantA->id]);
        $this->workerB = Worker::factory()->create(['code' => 'W-B', 'tenant_id' => $this->tenantB->id]);
    }

    public function test_the_worker_list_carries_only_the_own_tenants_workers(): void
    {
        $this->actingAs($this->adminA)
            ->getJson('/api/collections/workers')
            ->assertOk()
            ->assertJsonPath('channel', "col.{$this->tenantA->id}.workers")
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.code', 'W-A');
    }

    public function test_another_tenants_worker_cannot_be_opened_or_changed(): void
    {
        $this->actingAs($this->adminA);

        $this->get(route('admin.workers.show', $this->workerB))->assertNotFound();
        $this->get(route('admin.workers.edit', $this->workerB))->assertNotFound();
        $this->post(route('admin.workers.toggle-active', $this->workerB))->assertNotFound();
        // A DELETE that misses its record goes back with a notice instead of a
        // 404 page (bootstrap/app.php) — the same as for an already deleted one.
        $this->delete(route('admin.workers.destroy', $this->workerB))->assertSessionHas('info');

        $workerB = Worker::withoutGlobalScopes()->find($this->workerB->id);
        $this->assertNull($workerB->deleted_at);
        $this->assertTrue($workerB->is_active);
    }

    public function test_a_new_worker_lands_in_the_creators_tenant(): void
    {
        $this->actingAs($this->adminA)
            ->post(route('admin.workers.store'), ['code' => 'W-NEW', 'name' => 'New Worker'])
            ->assertRedirect();

        $this->assertSame($this->tenantA->id, Worker::withoutGlobalScopes()->where('code', 'W-NEW')->value('tenant_id'));
    }

    public function test_a_user_without_a_tenant_still_sees_every_worker(): void
    {
        $this->actingAs(User::factory()->create(['tenant_id' => null]));

        $this->assertSame(['W-A', 'W-B'], Worker::query()->orderBy('code')->pluck('code')->all());
    }

    public function test_the_migration_gives_existing_workers_a_tenant(): void
    {
        $migration = require database_path('migrations/2026_10_11_120000_add_tenant_id_to_workers_table.php');
        $migration->down();

        // A worker with an account in tenant B, and one with no account at all.
        $withAccount = DB::table('workers')->insertGetId(['code' => 'W-ACC', 'name' => 'Has account', 'created_at' => now(), 'updated_at' => now()]);
        $withoutAccount = DB::table('workers')->insertGetId(['code' => 'W-NONE', 'name' => 'No account', 'created_at' => now(), 'updated_at' => now()]);
        $account = User::factory()->create(['tenant_id' => $this->tenantB->id, 'worker_id' => $withAccount]);
        $scheduled = EmployeeActivity::factory()->create(['worker_id' => $withAccount]);

        $migration->up();

        $tenantOf = fn (int $id) => DB::table('workers')->where('id', $id)->value('tenant_id');
        $oldest = DB::table('tenants')->orderBy('id')->value('id');

        $this->assertSame($this->tenantB->id, $tenantOf($withAccount));
        $this->assertSame($oldest, $tenantOf($withoutAccount));
        $this->assertSame(0, DB::table('workers')->whereNull('tenant_id')->count());

        // Adding the column must not set off anything that points at workers.
        $this->assertSame($withAccount, DB::table('users')->where('id', $account->id)->value('worker_id'));
        $this->assertTrue(DB::table('employee_activities')->where('id', $scheduled->id)->exists());
    }
}
