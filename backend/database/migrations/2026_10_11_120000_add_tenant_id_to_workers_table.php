<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workers belong to a tenant, like users, lines and work orders already do.
 *
 * Until now the workers table had no tenant at all, so every tenant's users saw
 * every tenant's workers. Existing rows are backfilled from what already ties a
 * worker to a tenant, strongest first:
 *
 *   1. the worker's user accounts;
 *   2. their schedule (employee_activities) and pallet moves (pallet_movements);
 *   3. nothing at all — the oldest tenant; on a single-tenant installation, the
 *      only one.
 *
 * A worker whose records within one level point at different tenants is left
 * without a tenant rather than handed to one of them: no tenant-bound user sees
 * them, so nothing leaks to the wrong tenant (a user without a tenant still
 * does, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Nullable first, so the backfill can run before anything relies on it.
        //
        // No foreign key on SQLite. SQLite cannot add one to an existing table,
        // so the schema builder rebuilds the table instead — and dropping the
        // old copy fires every ON DELETE pointing at workers: users lose their
        // worker link, and schedules, absences and skills are deleted outright.
        // A plain column is added in place and touches nothing else.
        Schema::table('workers', function (Blueprint $table) {
            if (DB::getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('tenant_id')->nullable();
            } else {
                $table->foreignId('tenant_id')->nullable()->after('id')
                    ->constrained()->cascadeOnDelete();
            }
            $table->index('tenant_id');
        });

        // 2) Workers the existing records tie to a single tenant.
        $oldest = DB::table('tenants')->orderBy('id')->value('id');
        if ($oldest === null) {
            return; // no tenants, nothing to scope by
        }

        $decided = [];
        $conflicting = [];

        foreach ([$this->tenantsOf(['users']), $this->tenantsOf(['employee_activities', 'pallet_movements'])] as $level) {
            foreach ($level as $workerId => $tenantIds) {
                if (isset($decided[$workerId]) || isset($conflicting[$workerId])) {
                    continue;
                }

                if (count($tenantIds) === 1) {
                    $decided[$workerId] = $tenantIds[0];
                } else {
                    $conflicting[$workerId] = true;
                }
            }
        }

        $byTenant = [];
        foreach ($decided as $workerId => $tenantId) {
            $byTenant[$tenantId][] = $workerId;
        }

        foreach ($byTenant as $tenantId => $workerIds) {
            foreach (array_chunk($workerIds, 500) as $chunk) {
                DB::table('workers')->whereIn('id', $chunk)->update(['tenant_id' => $tenantId]);
            }
        }

        // 3) Workers nothing ties to a tenant. Left NULL they would vanish for
        // every user who has a tenant, which is every user once one exists.
        $undecided = DB::table('workers')->whereNull('tenant_id')->pluck('id')
            ->reject(fn ($id) => isset($conflicting[$id]))
            ->all();

        foreach (array_chunk($undecided, 500) as $chunk) {
            DB::table('workers')->whereIn('id', $chunk)->update(['tenant_id' => $oldest]);
        }
    }

    /**
     * The distinct tenants each worker's rows in the given tables belong to.
     *
     * @param  list<string>  $tables  each with worker_id and tenant_id
     * @return array<int, list<int>> worker id => tenant ids
     */
    private function tenantsOf(array $tables): array
    {
        $tenants = [];

        foreach ($tables as $table) {
            DB::table($table)
                ->whereNotNull('worker_id')
                ->whereNotNull('tenant_id')
                ->distinct()
                ->orderBy('worker_id')
                ->select(['worker_id', 'tenant_id'])
                ->get()
                ->each(function ($row) use (&$tenants) {
                    $tenants[(int) $row->worker_id][(int) $row->tenant_id] = true;
                });
        }

        return array_map(fn (array $set) => array_keys($set), $tenants);
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['tenant_id']);
            }
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
