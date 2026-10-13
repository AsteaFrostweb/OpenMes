<?php

namespace Tests\Feature\Web;

use App\Models\InspectionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inspection plans list counts each plan's criteria from the collection
 * snapshot — without the column every plan showed "0".
 */
class InspectionPlanCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_includes_criteria(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $plan = InspectionPlan::factory()->create([
            'criteria' => [
                ['name' => 'Thickness', 'type' => 'measurement', 'required' => true, 'unit' => 'mm', 'spec_min' => 1.9, 'spec_max' => 2.1],
                ['name' => 'Surface', 'type' => 'visual', 'required' => true, 'unit' => null, 'spec_min' => null, 'spec_max' => null],
            ],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/collections/inspection_plans')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('id', $plan->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('criteria', $row);

        // Raw JSON string in the snapshot (see the ShapeRegistry note).
        $criteria = is_string($row['criteria']) ? json_decode($row['criteria'], true) : $row['criteria'];
        $this->assertCount(2, $criteria);
        $this->assertSame('Thickness', $criteria[0]['name']);
    }

    public function test_snapshot_requires_authentication(): void
    {
        $this->getJson('/api/collections/inspection_plans')->assertUnauthorized();
    }
}
