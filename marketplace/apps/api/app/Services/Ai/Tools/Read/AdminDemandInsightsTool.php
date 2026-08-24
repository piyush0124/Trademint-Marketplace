<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Requirement;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;
use Illuminate\Support\Facades\DB;

/**
 * Where demand is, and where it is going unmet.
 *
 * Grouped counts by category and city, plus the requirements that attracted no
 * supplier response. That last figure is the commercially useful one: it names the
 * category/city combinations where the marketplace has buyers but not enough
 * suppliers, which is the actual input to a supplier-acquisition decision.
 *
 * Aggregates only — no requirement titles, no buyer identities. The AI summarises the
 * shape of demand, not who is asking for what.
 */
class AdminDemandInsightsTool extends BaseTool
{
    public function name(): string
    {
        return 'admin_demand_insights';
    }

    public function description(): string
    {
        return 'Aggregate demand analysis for administrators: requirement counts grouped by category or city, and the '
            .'count of published requirements that received no supplier response. Use for questions about which '
            .'categories or cities have the most demand, and where supply is short.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'group_by' => $this->stringProperty('Dimension to group demand by.', ['category', 'city', 'state']),
            'days' => $this->integerProperty('Restrict to requirements created in the last N days.', 1, 365),
            'limit' => $this->integerProperty('How many groups to return (default 10).', 1, 25),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Admin];
    }

    public function handle(User $user, array $arguments): array
    {
        $groupBy = (string) ($arguments['group_by'] ?? 'category');
        $limit = $this->limit($arguments, 10, 25);
        $since = isset($arguments['days']) && $arguments['days'] !== null
            ? now()->subDays((int) $arguments['days'])
            : null;

        $base = fn () => Requirement::query()
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            // Drafts are private intent, not demand — a buyer who never published
            // has not asked the marketplace for anything.
            ->where('status', '!=', RequirementStatus::Draft->value);

        $groups = match ($groupBy) {
            'city' => $base()
                ->join('locations', 'requirements.location_id', '=', 'locations.id')
                ->select('locations.city as label', DB::raw('count(*) as total'))
                ->groupBy('locations.city'),
            'state' => $base()
                ->join('locations', 'requirements.location_id', '=', 'locations.id')
                ->select('locations.state as label', DB::raw('count(*) as total'))
                ->groupBy('locations.state'),
            default => $base()
                ->join('categories', 'requirements.category_id', '=', 'categories.id')
                ->select('categories.name as label', DB::raw('count(*) as total'))
                ->groupBy('categories.name'),
        };

        $demand = $groups->orderByDesc('total')->limit($limit)->get();

        /*
         * Unmet demand: published requirements with no invited supplier response and
         * no quote. Counted with `whereDoesntHave` rather than a left join so the
         * "no rows at all" case is handled correctly instead of producing nulls.
         */
        $unmet = $base()
            ->whereDoesntHave('quotes')
            ->whereDoesntHave('matches', fn ($q) => $q->where('response_status', '!=', 'pending'))
            ->join('categories', 'requirements.category_id', '=', 'categories.id')
            ->leftJoin('locations', 'requirements.location_id', '=', 'locations.id')
            ->select('categories.name as category', 'locations.city as city', DB::raw('count(*) as total'))
            ->groupBy('categories.name', 'locations.city')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return [
            'grouped_by' => $groupBy,
            'window_days' => $since === null ? null : (int) $arguments['days'],
            'demand' => $demand->map(fn ($row): array => [
                'label' => $row->label ?? 'Unspecified',
                'requirements' => (int) $row->total,
            ])->all(),
            'unmet_demand' => $unmet->map(fn ($row): array => [
                'category' => $row->category,
                'city' => $row->city ?? 'Unspecified',
                'requirements_without_response' => (int) $row->total,
            ])->all(),
            'note' => 'Draft requirements are excluded. Unmet demand means published requirements with no quote and no supplier response.',
        ];
    }
}
