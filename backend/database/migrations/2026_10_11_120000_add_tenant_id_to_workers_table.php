<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workers belong to a tenant, like users, lines and work orders already do.
 *
 * Until now the workers table had no tenant at all, so every tenant's users saw
 * every tenant's workers. Existing rows are backfilled: a worker linked to a
 * user account takes that account's tenant; any other worker goes to the oldest
 * tenant — on a single-tenant installation, the only one.
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

        // 2) From the worker's user account. Portable subquery form so it runs
        // on both Postgres and SQLite (tests).
        DB::table('workers')
            ->whereNull('tenant_id')
            ->update([
                'tenant_id' => DB::raw('(SELECT MIN(users.tenant_id) FROM users WHERE users.worker_id = workers.id)'),
            ]);

        // 3) Everyone else to the oldest tenant. Left NULL they would vanish
        // for every user who has a tenant, which is every user once one exists.
        $oldest = DB::table('tenants')->orderBy('id')->value('id');

        if ($oldest !== null) {
            DB::table('workers')->whereNull('tenant_id')->update(['tenant_id' => $oldest]);
        }
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
