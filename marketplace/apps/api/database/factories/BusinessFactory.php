<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            // A Business always belongs to an Owner-role account.
            'user_id' => User::factory()->owner(),
            'location_id' => Location::factory(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => $name,
            'about' => fake()->sentence(12),
            'phone' => fake()->numerify('##########'),
            'whatsapp' => fake()->numerify('##########'),
            'email' => fake()->companyEmail(),
            'website' => fake()->url(),
            'is_verified' => false,
            'is_available' => true,
        ];
    }

    /** Every identity dimension actually checked by an admin. */
    public function verified(): static
    {
        return $this->state(fn () => [
            'is_verified' => true,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'business_verified_at' => now(),
            'address_verified_at' => now(),
        ]);
    }

    /** Explicitly not taking on new work — the matching engine treats this as a hard signal. */
    public function unavailable(): static
    {
        return $this->state(fn () => ['is_available' => false]);
    }

    /** A well rated business with enough reviews for the rating to carry full confidence. */
    public function rated(float $rating = 4.8, int $reviews = 40): static
    {
        return $this->state(fn () => [
            'rating' => $rating,
            'review_count' => $reviews,
        ]);
    }
}
