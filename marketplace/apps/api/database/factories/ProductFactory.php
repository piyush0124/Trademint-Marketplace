<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'unit' => fake()->randomElement(['piece', 'kg', 'box', 'dozen']),
            'min_order' => fake()->numberBetween(1, 10),
            'max_order' => fake()->numberBetween(50, 500),
            'capacity' => fake()->numberBetween(100, 5000).' units/month',
            'customization_available' => fake()->boolean(),
            'is_active' => true,
        ];
    }
}
