<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'min_order' => fake()->numberBetween(1, 5),
            'estimated_delivery_time' => fake()->numberBetween(1, 14).' days',
            'customization_available' => fake()->boolean(),
            'is_active' => true,
        ];
    }
}
