<?php

namespace Database\Factories;

use App\Enums\AvailabilityStatus;
use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    public function definition(): array
    {
        return [
            // A Listing's owner is structurally an Owner-role account —
            // ListingPolicy requires isOwner() to create one, so factory
            // data should reflect that by default too.
            'user_id' => User::factory()->owner(),
            'category_id' => Category::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'price' => fake()->numberBetween(500000, 20000000),
            'currency' => 'INR',
            'status' => ListingStatus::Draft,
            'availability_status' => AvailabilityStatus::Available,
        ];
    }
}
