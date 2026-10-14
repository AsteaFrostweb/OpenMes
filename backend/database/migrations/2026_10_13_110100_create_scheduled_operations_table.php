<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning state of one routing step of one work order: where and when the
 * finite-capacity scheduler placed it, or why it could not.
 *
 * A projection, not routing master data — durations, operator counts and
 * equipment classes are read from the work order's frozen process snapshot
 * each time a schedule is generated; this table only records the outcome.
 * Rows with a simulation id belong to a what-if draft; rows without one are
 * the committed plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('schedule_simulation_id')->nullable()->constrained('schedule_simulations')->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('batch_step_id')->nullable()->constrained('batch_steps')->nullOnDelete();
            $table->unsignedInteger('step_number');
            $table->string('step_name')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('line_id')->nullable()->constrained('lines')->nullOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained('workstations')->nullOnDelete();
            $table->foreignId('workstation_type_id')->nullable()->constrained('workstation_types')->nullOnDelete();
            // fixed (routing/batch step names it), auto (picked from the
            // equipment class pool), manual (overridden by a planner), line
            // (no station information — the whole line is the resource).
            $table->string('resource_source', 10)->nullable();
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('setup_minutes')->nullable();
            $table->unsignedSmallInteger('required_operators')->default(1);
            $table->string('status', 40);
            $table->json('reasons')->nullable();
            $table->timestamps();

            $table->index(['schedule_simulation_id', 'work_order_id']);
            $table->index(['workstation_id', 'planned_start_at']);
            $table->index(['line_id', 'planned_start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_operations');
    }
};
