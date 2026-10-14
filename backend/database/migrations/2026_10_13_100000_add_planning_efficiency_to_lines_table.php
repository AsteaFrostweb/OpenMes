<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning efficiency: the share of a line's calendar capacity (shifts minus
 * scheduled maintenance) that planning may actually load.
 *
 * Deliberately not OEE. Calendar capacity already removes the availability
 * losses we know about, so multiplying by a full OEE figure would count them
 * twice. The default of 100 keeps every existing installation's capacity
 * figures exactly where they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lines', function (Blueprint $table) {
            $table->decimal('planning_efficiency_pct', 5, 2)->default(100)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('lines', function (Blueprint $table) {
            $table->dropColumn('planning_efficiency_pct');
        });
    }
};
