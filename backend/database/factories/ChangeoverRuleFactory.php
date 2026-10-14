<?php

namespace Database\Factories;

use App\Models\ChangeoverRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeoverRule>
 */
class ChangeoverRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'attribute' => ChangeoverRule::ATTRIBUTE_PRODUCT_CATEGORY,
            'from_value' => null,
            'to_value' => null,
            'setup_minutes' => 20,
            'is_active' => true,
        ];
    }
}
