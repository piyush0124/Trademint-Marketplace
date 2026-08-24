<?php

namespace App\Services\Ai\Tools\Write;

use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Requirement;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;
use App\Services\RequirementService;
use Illuminate\Validation\ValidationException;

/**
 * Amend a requirement that is still a draft.
 *
 * Draft-only, and that restriction is the point. Once a requirement is published,
 * suppliers have been invited and may already be pricing against it — silently
 * changing the quantity or budget underneath them would invalidate work in progress.
 * Editing a live requirement is a human decision made in the UI with that consequence
 * in view, not something an assistant does because a sentence implied it.
 *
 * Like every write tool this is confirmation-gated, and it delegates to
 * RequirementService so an AI edit and a form edit are the same operation.
 */
class UpdateRequirementDraftTool extends BaseTool
{
    public function __construct(private readonly RequirementService $requirements) {}

    public function name(): string
    {
        return 'update_requirement_draft';
    }

    public function description(): string
    {
        return 'Update fields on one of the signed-in customer\'s DRAFT requirements. Requires explicit confirmation. '
            .'Only works while the requirement is a draft — a published requirement cannot be edited this way. '
            .'Send only the fields that should change.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'requirement_id' => $this->stringProperty('Uuid of the draft requirement to update.'),
            'title' => $this->stringProperty('New title.'),
            'description' => $this->stringProperty('New description.'),
            'category' => $this->stringProperty('New category slug from list_categories.'),
            'quantity' => ['type' => 'number', 'description' => 'New quantity.', 'minimum' => 0],
            'unit' => $this->stringProperty('New unit of measure.'),
            'budget_min' => ['type' => 'number', 'description' => 'New lower budget bound in INR.', 'minimum' => 0],
            'budget_max' => ['type' => 'number', 'description' => 'New upper budget bound in INR.', 'minimum' => 0],
            'city' => $this->stringProperty('New city.'),
            'state' => $this->stringProperty('New state.'),
            'required_date' => $this->stringProperty('New required-by date as YYYY-MM-DD.'),
            'urgency' => $this->stringProperty('New urgency.', ['low', 'normal', 'high', 'urgent']),
        ], ['requirement_id']);
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function roles(): array
    {
        return [UserRole::Customer];
    }

    /** Must be the caller's own requirement, and still a draft. */
    public function authorize(User $user, array $arguments): bool
    {
        $requirement = $this->findDraft($user, (string) ($arguments['requirement_id'] ?? ''));

        if ($requirement === null) {
            return false;
        }

        // A category change is only permissible to a category that exists.
        if (! empty($arguments['category'])) {
            return Category::where('slug', $arguments['category'])->where('is_active', true)->exists();
        }

        return true;
    }

    public function confirmationSummary(array $arguments): ?string
    {
        // Field labels, not values, for the ones that are long free text — the user
        // recognises "description" more usefully than a truncated paragraph.
        $changes = collect($arguments)
            ->except('requirement_id')
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(function ($value, string $key): string {
                if (in_array($key, ['description', 'title'], true)) {
                    return str_replace('_', ' ', $key);
                }

                return sprintf('%s to %s', str_replace('_', ' ', $key), is_scalar($value) ? $value : '…');
            })
            ->values();

        if ($changes->isEmpty()) {
            return 'Update the draft requirement (no changes specified).';
        }

        return sprintf('Update your draft requirement: change %s.', $changes->implode(', '));
    }

    public function handle(User $user, array $arguments): array
    {
        // Re-fetched, because the requirement may have been published or cancelled
        // between the proposal and the confirmation.
        $requirement = $this->findDraft($user, (string) $arguments['requirement_id']);

        if ($requirement === null) {
            throw ValidationException::withMessages([
                'requirement' => ['That draft is no longer editable. It may have been published or cancelled.'],
            ]);
        }

        $payload = [];

        foreach (['title', 'description', 'quantity', 'unit', 'budget_min', 'budget_max', 'required_date', 'urgency'] as $field) {
            // array_key_exists, not isset: an explicit null means "clear this field",
            // which is a legitimate edit and must not be silently dropped.
            if (array_key_exists($field, $arguments)) {
                $payload[$field] = $arguments[$field];
            }
        }

        if (! empty($arguments['category'])) {
            $category = Category::where('slug', $arguments['category'])->where('is_active', true)->first();

            if ($category === null) {
                throw ValidationException::withMessages(['category' => ['That category no longer exists.']]);
            }

            $payload['category_id'] = $category->id;
        }

        if (! empty($arguments['city'])) {
            $payload['location'] = [
                'city' => $arguments['city'],
                'state' => $arguments['state'] ?? null,
                'country' => 'IN',
            ];
        }

        $budgetMin = $payload['budget_min'] ?? $requirement->budget_min;
        $budgetMax = $payload['budget_max'] ?? $requirement->budget_max;

        if ($budgetMin !== null && $budgetMax !== null && (float) $budgetMin > (float) $budgetMax) {
            throw ValidationException::withMessages([
                'budget' => ['The minimum budget cannot exceed the maximum.'],
            ]);
        }

        $updated = $this->requirements->updateDraft($requirement, $payload);

        return [
            'updated' => true,
            'requirement_id' => $updated->uuid,
            'title' => $updated->title,
            'status' => $updated->status->value,
            'fields_changed' => array_keys($payload),
        ];
    }

    private function findDraft(User $user, string $uuid): ?Requirement
    {
        return Requirement::query()
            ->where('uuid', $uuid)
            ->where('customer_id', $user->id)
            ->where('status', RequirementStatus::Draft->value)
            ->first();
    }
}
