<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Requirement;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * The caller's own requirements.
 *
 * Note the absence of any customer identifier in the schema. "Whose requirements" is
 * answered by `$user`, which the runner takes from the authenticated session — so a
 * model that emits `{"customer_id": 42}` gets that argument dropped and still sees
 * only its own user's rows. This is the pattern for every personal-data tool here.
 */
class GetMyRequirementsTool extends BaseTool
{
    public function name(): string
    {
        return 'get_my_requirements';
    }

    public function description(): string
    {
        return "List the signed-in customer's own requirements, newest first, optionally filtered by status. "
            .'Use this to answer questions about what they have posted and where it stands.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'status' => $this->stringProperty(
                'Filter by requirement status.',
                array_column(RequirementStatus::cases(), 'value'),
            ),
            'limit' => $this->integerProperty('Maximum requirements to return (default 5).', 1, 20),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Customer];
    }

    public function handle(User $user, array $arguments): array
    {
        $query = Requirement::query()
            // Ownership is a scope on the query, not a filter the caller can omit.
            ->where('customer_id', $user->id)
            ->with(['category:id,name', 'location:id,city,state'])
            ->withCount(['quotes', 'matches'])
            ->latest('id');

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        $requirements = $query->limit($this->limit($arguments))->get();

        return [
            'count' => $requirements->count(),
            'requirements' => $requirements->map(fn (Requirement $requirement): array => [
                'id' => $requirement->uuid,
                'title' => $requirement->title,
                'status' => $requirement->status->value,
                'category' => $requirement->category?->name,
                'city' => $requirement->location?->city,
                'quantity' => $requirement->quantity,
                'unit' => $requirement->unit,
                'budget_min' => $requirement->budget_min,
                'budget_max' => $requirement->budget_max,
                'currency' => config('marketplace.default_currency'),
                'required_date' => $requirement->required_date?->toDateString(),
                'quotes_received' => (int) $requirement->quotes_count,
                'suppliers_matched' => (int) $requirement->matches_count,
                'created_at' => $requirement->created_at?->toDateString(),
            ])->all(),
        ];
    }
}
