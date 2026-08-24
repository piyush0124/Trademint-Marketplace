<?php

namespace Database\Factories;

use App\Enums\RequirementStatus;
use App\Enums\RequirementUrgency;
use App\Models\Category;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Requirement>
 */
class RequirementFactory extends Factory
{
    protected $model = Requirement::class;

    public function definition(): array
    {
        return [
            // A Requirement's owner is structurally a Customer-role account —
            // RequirementPolicy requires isCustomer() to create one, and the
            // default UserFactory role is already Customer.
            'customer_id' => User::factory(),
            'category_id' => Category::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'quantity' => fake()->numberBetween(1, 500),
            'unit' => 'units',
            'budget_min' => fake()->numberBetween(1000, 50000),
            'budget_max' => fake()->numberBetween(60000, 200000),
            'required_date' => now()->addDays(fake()->numberBetween(7, 60))->toDateString(),
            'urgency' => RequirementUrgency::Normal,
            'status' => RequirementStatus::Draft,
        ];
    }
}
