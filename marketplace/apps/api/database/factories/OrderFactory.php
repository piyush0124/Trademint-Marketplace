<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 *
 * Orders are normally created by OrderService from an accepted quote, and tests that
 * exercise the order workflow should keep going through that path — it is the code under
 * test. This factory exists for the *other* case: a test that needs an order to hang
 * something off (a review, a payment, a dispute) and does not care how it came to exist.
 *
 * Money and status are set through `forceFill` in the states below because they are
 * absent from `$fillable` by design — the model refuses to let a client price or progress
 * an order, and a factory is not an exception worth carving out.
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $total = $this->faker->numberBetween(10000, 500000);

        return [
            'uuid' => (string) Str::uuid(),
            // Matches the ReferenceNumberGenerator shape without reserving a real number.
            'order_number' => 'ORD-'.now()->year.'-'.$this->faker->unique()->numerify('######'),
            'quote_id' => Quote::factory(),
            'requirement_id' => Requirement::factory(),
            'customer_id' => User::factory(),
            'business_id' => Business::factory(),
            'currency' => config('marketplace.default_currency', 'INR'),
            'subtotal' => $total,
            'total_amount' => $total,
            'placed_at' => now(),
        ];
    }

    /** A completed order — the precondition for a review. */
    public function completed(): static
    {
        return $this->afterCreating(fn (Order $order) => $order->forceFill([
            'status' => OrderStatus::Completed,
            'confirmed_at' => now()->subDays(10),
            'completed_at' => now(),
        ])->save());
    }

    public function withStatus(OrderStatus $status): static
    {
        return $this->afterCreating(fn (Order $order) => $order->forceFill(['status' => $status])->save());
    }
}
