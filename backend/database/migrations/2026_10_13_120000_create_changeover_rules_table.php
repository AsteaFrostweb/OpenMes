<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequence-dependent setup: how long changing over takes when one kind of job
 * follows another on the same resource.
 *
 * Optional — with no rule the routing's standard setup_time_minutes applies
 * unchanged. A rule matches on one attribute of the previous and the next job
 * (product type code or product category), either side left empty meaning
 * "any", and can be narrowed to an equipment class or a single workstation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('changeover_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('name');
            $table->string('attribute', 30);
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->foreignId('workstation_type_id')->nullable()->constrained('workstation_types')->nullOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained('workstations')->nullOnDelete();
            $table->unsignedInteger('setup_minutes');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['attribute', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changeover_rules');
    }
};
