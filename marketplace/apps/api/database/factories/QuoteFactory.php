<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Enquiry;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 *
 * Note the totals here are only placeholders for tests that don't care about
 * them. Anything asserting on money should go through QuoteService, which is
 * the only thing allowed to calculate a real total.
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'enquiry_id' => Enquiry::factory(),
            'quote_number' => sprintf('QT-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(12),
            'currency' => config('marketplace.default_currency', 'INR'),
            'status' => QuoteStatus::Draft,
            'subtotal' => 0,
            'tax_amount' => 0,
            'delivery_fee' => 0,
            'discount_amount' => 0,
            'other_charges' => 0,
            'total_amount' => 0,
        ];
    }

    /**
     * Derives requirement/customer/business from the enquiry so factory-made
     * quotes are always internally consistent — the same invariant
     * QuoteService guarantees in production.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Quote $quote): void {
            $enquiry = $quote->enquiry ?? Enquiry::find($quote->enquiry_id);

            if ($enquiry !== null) {
                $quote->requirement_id ??= $enquiry->requirement_id;
                $quote->customer_id ??= $enquiry->customer_id;
                $quote->business_id ??= $enquiry->business_id;
            }
        });
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => QuoteStatus::Sent,
            'sent_at' => now(),
            'validity_date' => now()->addDays(14)->toDateString(),
            'expires_at' => now()->addDays(14)->endOfDay(),
        ]);
    }

    /** Sent, but its validity window has already closed. */
    public function lapsed(): static
    {
        return $this->state(fn () => [
            'status' => QuoteStatus::Sent,
            'sent_at' => now()->subDays(30),
            'validity_date' => now()->subDay()->toDateString(),
            'expires_at' => now()->subDay()->endOfDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => QuoteStatus::Accepted,
            'sent_at' => now()->subDay(),
            'viewed_at' => now()->subHours(6),
            'accepted_at' => now(),
        ]);
    }
}
