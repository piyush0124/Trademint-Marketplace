<?php

namespace App\Services\Ai\Tools\Write;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Location;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;
use App\Services\RequirementService;
use Illuminate\Validation\ValidationException;

/**
 * Create a requirement DRAFT on the customer's behalf.
 *
 * The most consequential tool in the layer, and deliberately the most constrained:
 *
 *  - `isWrite()` is true, so it can never run on the turn the model picks it. It
 *    becomes a pending `ai_actions` row and executes only after the customer
 *    explicitly confirms, through a separate request.
 *  - It creates a **draft**, never a published requirement. Publishing stays a
 *    deliberate human act, so nothing is exposed to suppliers because an AI
 *    misread a sentence.
 *  - The category is resolved against the `categories` table by slug. A slug the AI
 *    invented simply fails validation — there is no code path that creates a category.
 *  - The location is resolved through the existing RequirementService, which reuses a
 *    matching row and only inserts when the place genuinely is new. Free-text city is
 *    correct for a pan-India marketplace; inventing a *category* is not the same risk
 *    as recording a city the AI read off the user's own message.
 *
 * Creation itself is delegated to RequirementService, so an AI-created draft is
 * byte-for-byte the same as a form-created one and inherits every rule.
 */
class CreateRequirementDraftTool extends BaseTool
{
    public function __construct(private readonly RequirementService $requirements) {}

    public function name(): string
    {
        return 'create_requirement_draft';
    }

    public function description(): string
    {
        return 'Create a DRAFT requirement for the signed-in customer. Requires explicit confirmation before it runs. '
            .'The draft is never published automatically — the customer reviews and publishes it themselves. '
            .'Only call this once you have a valid category slug from list_categories and the customer has agreed.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'category' => $this->stringProperty('Category slug from list_categories. Must exist.'),
            'title' => $this->stringProperty('Short title describing what is needed.'),
            'description' => $this->stringProperty('Fuller description in the customer\'s own words.'),
            'quantity' => ['type' => 'number', 'description' => 'How many/much is needed.', 'minimum' => 0],
            'unit' => $this->stringProperty('Unit of measure, e.g. pieces, sq ft, tonnes, hours, licences.'),
            'budget_min' => ['type' => 'number', 'description' => 'Lower budget bound in INR, as a plain number.', 'minimum' => 0],
            'budget_max' => ['type' => 'number', 'description' => 'Upper budget bound in INR, as a plain number.', 'minimum' => 0],
            'city' => $this->stringProperty('City where the work or delivery is needed.'),
            'state' => $this->stringProperty('State, if the customer mentioned one.'),
            'area' => $this->stringProperty('Area or locality, if mentioned.'),
            'required_date' => $this->stringProperty('Date needed by, as YYYY-MM-DD.'),
            'urgency' => $this->stringProperty('How urgent it is.', ['low', 'normal', 'high', 'urgent']),
        ], ['category', 'title']);
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function roles(): array
    {
        return [UserRole::Customer];
    }

    /**
     * The category must genuinely exist and be active.
     *
     * Checked here as well as in `handle()` so an invented slug is caught *before* the
     * customer is shown a confirmation prompt for something that cannot succeed.
     */
    public function authorize(User $user, array $arguments): bool
    {
        return Category::query()
            ->where('slug', (string) ($arguments['category'] ?? ''))
            ->where('is_active', true)
            ->exists();
    }

    /**
     * What the customer is agreeing to, composed from the validated arguments.
     *
     * Built server-side on purpose. If the model says "I'll post your requirement for
     * 500 chairs" but the arguments say 50, this prompt shows 50 — because 50 is what
     * will actually be written.
     */
    public function confirmationSummary(array $arguments): ?string
    {
        $category = Category::where('slug', $arguments['category'] ?? '')->value('name');

        $parts = array_filter([
            sprintf('Create a draft requirement "%s"', $arguments['title'] ?? 'Untitled'),
            $category !== null ? sprintf('in %s', $category) : null,
            ! empty($arguments['city']) ? sprintf('for %s', $arguments['city']) : null,
            isset($arguments['quantity']) && $arguments['quantity'] !== null
                ? sprintf('quantity %s%s', $arguments['quantity'], ! empty($arguments['unit']) ? ' '.$arguments['unit'] : '')
                : null,
            $this->budgetPhrase($arguments),
            ! empty($arguments['required_date']) ? sprintf('needed by %s', $arguments['required_date']) : null,
        ]);

        return implode(', ', $parts).'. It will stay a draft until you publish it.';
    }

    public function handle(User $user, array $arguments): array
    {
        // Re-resolved at execution time: a category can be deactivated between
        // proposal and confirmation.
        $category = Category::query()
            ->where('slug', (string) $arguments['category'])
            ->where('is_active', true)
            ->first();

        if ($category === null) {
            throw ValidationException::withMessages([
                'category' => ['That category no longer exists. Please choose another.'],
            ]);
        }

        if (isset($arguments['budget_min'], $arguments['budget_max'])
            && (float) $arguments['budget_min'] > (float) $arguments['budget_max']) {
            throw ValidationException::withMessages([
                'budget' => ['The minimum budget cannot exceed the maximum.'],
            ]);
        }

        $requirement = $this->requirements->createDraft($user, [
            'category_id' => $category->id,
            'title' => $arguments['title'] ?? null,
            'description' => $arguments['description'] ?? null,
            'quantity' => $arguments['quantity'] ?? null,
            'unit' => $arguments['unit'] ?? null,
            'budget_min' => $arguments['budget_min'] ?? null,
            'budget_max' => $arguments['budget_max'] ?? null,
            'required_date' => $arguments['required_date'] ?? null,
            'urgency' => $arguments['urgency'] ?? 'normal',
            'location' => empty($arguments['city']) ? null : [
                'city' => $arguments['city'],
                'state' => $arguments['state'] ?? null,
                'area' => $arguments['area'] ?? null,
                'country' => 'IN',
            ],
        ]);

        return [
            'created' => true,
            'requirement_id' => $requirement->uuid,
            'status' => $requirement->status->value,
            'title' => $requirement->title,
            'category' => $category->name,
            'city' => $requirement->location?->city,
            // The honest next step: a draft does nothing until it is published, and
            // publishing has its own completeness rules.
            'next_step' => 'The draft is saved. Review it and publish when ready — publishing needs a title, quantity, location and required date.',
        ];
    }

    private function budgetPhrase(array $arguments): ?string
    {
        $min = $arguments['budget_min'] ?? null;
        $max = $arguments['budget_max'] ?? null;
        $currency = (string) config('marketplace.default_currency', 'INR');

        return match (true) {
            $min !== null && $max !== null => sprintf('budget %s %s–%s', $currency, $min, $max),
            $max !== null => sprintf('budget up to %s %s', $currency, $max),
            $min !== null => sprintf('budget from %s %s', $currency, $min),
            default => null,
        };
    }
}
