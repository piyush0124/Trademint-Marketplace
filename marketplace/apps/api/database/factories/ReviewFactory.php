<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Review>
 *
 * Reviews are normally created by ReviewService, which enforces the eligibility rules —
 * completed order, correct customer, once only. Tests about those rules must keep using
 * that path.
 *
 * This factory is for tests that need a review to exist so they can test something else,
 * such as drafting a public response to one. It still attaches a completed order, because
 * `reviews.order_id` is a non-nullable unique FK and a review without one would be a row
 * the application could never have produced.
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'order_id' => Order::factory()->completed(),
            'business_id' => Business::factory(),
            'customer_id' => User::factory(),
            'rating' => $this->faker->numberBetween(3, 5),
            'comment' => $this->faker->sentence(12),
        ];
    }

    /**
     * Status is force-filled: the model keeps it out of `$fillable` so it can only move
     * through moderation, and the factory respects that rather than widening the model.
     */
    public function published(): static
    {
        return $this->afterCreating(fn (Review $review) => $review->forceFill(['status' => ReviewStatus::Published])->save());
    }

    public function flagged(): static
    {
        return $this->afterCreating(fn (Review $review) => $review->forceFill([
            'status' => ReviewStatus::Flagged,
            'moderated_at' => now(),
        ])->save());
    }
}
