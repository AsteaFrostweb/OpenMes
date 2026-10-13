<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A finite-capacity schedule calculated but not yet committed (what-if).
 *
 * Generating one changes nothing on the shop floor; only an explicit apply
 * writes its result through the planner's own update path. Not a CRUD entity a
 * user deletes — a draft is discarded (status), and its operations go with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('horizon_start');
            $table->timestamp('horizon_end');
            $table->json('parameters')->nullable();
            $table->json('summary')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_simulations');
    }
};
