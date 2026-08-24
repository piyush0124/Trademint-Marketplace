<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\MatchResponseStatus;
use App\Enums\UserRole;
use App\Models\RequirementMatch;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * Requirements this business was actually invited to.
 *
 * Scoped to `requirement_matches` rows belonging to the caller's own business, which
 * is the same restriction the Phase 4 opportunities page enforces. Crucially this is
 * *not* a window onto the open requirement pool: a business sees the work the
 * matching engine invited it to and nothing else, so the AI cannot be used to
 * enumerate other people's buying intentions.
 *
 * The match score and reasons come from the stored row, computed by the deterministic
 * engine. The AI reports them; it does not produce them.
 */
class GetBusinessOpportunitiesTool extends BaseTool
{
    public function name(): string
    {
        return 'get_business_opportunities';
    }

    public function description(): string
    {
        return 'List requirements the signed-in business has been matched and invited to, with the stored match score '
            .'and the reasons the engine gave. Use for "what work is available to me" questions.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'response_status' => $this->stringProperty(
                'Filter by whether the business has responded.',
                array_column(MatchResponseStatus::cases(), 'value'),
            ),
            'limit' => $this->integerProperty('Maximum opportunities to return (default 5).', 1, 15),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Owner];
    }

    public function handle(User $user, array $arguments): array
    {
        $business = $user->business;

        if ($business === null) {
            return ['count' => 0, 'opportunities' => [], 'note' => 'Create a business profile to receive opportunities.'];
        }

        $query = RequirementMatch::query()
            ->where('business_id', $business->id)
            // Only invited matches are visible to the business — an un-invited
            // candidate row is internal to the engine.
            ->whereNotNull('invited_at')
            ->with(['requirement.category:id,name', 'requirement.location:id,city,state'])
            ->orderByDesc('match_score');

        if (! empty($arguments['response_status'])) {
            $query->where('response_status', $arguments['response_status']);
        }

        $matches = $query->limit($this->limit($arguments))->get();

        return [
            'count' => $matches->count(),
            'opportunities' => $matches->map(function (RequirementMatch $match): array {
                $requirement = $match->requirement;

                return [
                    'match_id' => $match->uuid,
                    'requirement_title' => $requirement?->title,
                    'category' => $requirement?->category?->name,
                    'city' => $requirement?->location?->city,
                    'quantity' => $requirement?->quantity,
                    'unit' => $requirement?->unit,
                    'budget_min' => $requirement?->budget_min,
                    'budget_max' => $requirement?->budget_max,
                    'required_date' => $requirement?->required_date?->toDateString(),
                    // Reproducible figure from the stored match, not a fresh guess.
                    'match_score' => (float) $match->match_score,
                    'match_reasons' => array_values((array) ($match->match_reason['reasons'] ?? [])),
                    'response_status' => $match->response_status->value,
                    'invited_at' => $match->invited_at?->toDateString(),
                ];
            })->all(),
            // The buyer's identity is deliberately absent until the business engages
            // through an enquiry, so this tool cannot be used to harvest contacts.
            'note' => 'Customer identity is not exposed at the opportunity stage.',
        ];
    }
}
