<?php

namespace Database\Factories;

use App\Enums\EnquiryStatus;
use App\Models\Business;
use App\Models\Enquiry;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enquiry>
 */
class EnquiryFactory extends Factory
{
    protected $model = Enquiry::class;

    public function definition(): array
    {
        return [
            'requirement_id' => Requirement::factory(),
            'customer_id' => User::factory(),
            'business_id' => Business::factory(),
            'status' => EnquiryStatus::Pending,
            'initiated_by' => 'customer',
            'customer_message' => fake()->sentence(10),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => EnquiryStatus::Accepted,
            'accepted_at' => now(),
            'business_message' => 'Happy to help with this.',
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn () => [
            'status' => EnquiryStatus::Declined,
            'declined_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => EnquiryStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
