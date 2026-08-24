<?php

namespace Database\Factories;

use App\Enums\MatchResponseStatus;
use App\Models\Business;
use App\Models\Requirement;
use App\Models\RequirementMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequirementMatch>
 */
class RequirementMatchFactory extends Factory
{
    protected $model = RequirementMatch::class;

    public function definition(): array
    {
        $score = fake()->randomFloat(2, 40, 95);

        return [
            'requirement_id' => Requirement::factory(),
            'business_id' => Business::factory(),
            'match_score' => $score,
            'match_reason' => [
                'reasons' => ['Primary category matches', 'Operates in the same city'],
                'breakdown' => [],
                'capabilities' => [],
                'scored_at' => now()->toIso8601String(),
            ],
            'invited_at' => now(),
            'viewed_at' => null,
            'response_status' => MatchResponseStatus::Pending,
        ];
    }

    public function viewed(): static
    {
        return $this->state(fn () => ['viewed_at' => now()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'viewed_at' => now(),
            'response_status' => MatchResponseStatus::Accepted,
            'responded_at' => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn () => [
            'viewed_at' => now(),
            'response_status' => MatchResponseStatus::Declined,
            'responded_at' => now(),
            'response_note' => 'Outside our current capacity.',
        ]);
    }
}
