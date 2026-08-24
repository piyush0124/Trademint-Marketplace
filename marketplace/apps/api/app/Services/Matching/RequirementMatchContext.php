<?php

namespace App\Services\Matching;

use App\Models\Category;
use App\Models\Requirement;
use Illuminate\Support\Str;

/**
 * Everything the scorer needs to know about a requirement, resolved once
 * per matching run instead of once per candidate business.
 *
 * This exists for two reasons. It keeps the category-family lookup and the
 * location normalisation out of the per-business loop (they're the same for
 * every candidate), and it makes BusinessMatchScorer a pure function of
 * (context, business) so each dimension can be unit tested in isolation.
 *
 * Nothing here is marketplace-specific: no city, category or industry is
 * named, everything is read off the requirement row.
 */
final class RequirementMatchContext
{
    private function __construct(
        public readonly int $categoryId,
        public readonly ?string $categoryName,
        /** @var int[] Parent + children of the requirement's category — a near miss, not an exact one. */
        public readonly array $relatedCategoryIds,
        public readonly ?string $city,
        public readonly ?string $cityLabel,
        public readonly ?string $state,
        public readonly ?string $country,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?float $quantity,
        public readonly ?string $unit,
        public readonly ?float $budgetMin,
        public readonly ?float $budgetMax,
        public readonly ?int $daysUntilRequired,
    ) {}

    public static function for(Requirement $requirement): self
    {
        $requirement->loadMissing(['category', 'location']);

        $location = $requirement->location;
        $requiredDate = $requirement->required_date;

        return new self(
            categoryId: (int) $requirement->category_id,
            categoryName: $requirement->category?->name,
            relatedCategoryIds: self::relatedCategoryIds($requirement->category),
            city: self::normalise($location?->city),
            cityLabel: $location?->city,
            state: self::normalise($location?->state),
            country: self::normalise($location?->country),
            latitude: $location?->latitude !== null ? (float) $location->latitude : null,
            longitude: $location?->longitude !== null ? (float) $location->longitude : null,
            quantity: $requirement->quantity !== null ? (float) $requirement->quantity : null,
            unit: $requirement->unit,
            budgetMin: $requirement->budget_min !== null ? (float) $requirement->budget_min : null,
            budgetMax: $requirement->budget_max !== null ? (float) $requirement->budget_max : null,
            // Negative days (an already-passed date) are clamped to 0 — the
            // work is needed immediately, not "minus three days ago".
            daysUntilRequired: $requiredDate !== null ? max(0, (int) now()->startOfDay()->diffInDays($requiredDate->copy()->startOfDay(), false)) : null,
        );
    }

    /** True when this category id is the requirement's own category. */
    public function isExactCategory(?int $categoryId): bool
    {
        return $categoryId !== null && $categoryId === $this->categoryId;
    }

    /** True when this category id is a parent or child of the requirement's category. */
    public function isRelatedCategory(?int $categoryId): bool
    {
        return $categoryId !== null && in_array($categoryId, $this->relatedCategoryIds, true);
    }

    public function hasBudget(): bool
    {
        return $this->budgetMin !== null || $this->budgetMax !== null;
    }

    /**
     * The category tree is one level deep by convention, so "related" means
     * the parent of the requirement's category plus its direct children.
     * Siblings are deliberately excluded — two children of one parent are
     * often mutually irrelevant (boxes vs. tape), so treating them as a
     * partial match would produce noise rather than useful matches.
     */
    private static function relatedCategoryIds(?Category $category): array
    {
        if ($category === null) {
            return [];
        }

        $ids = $category->parent_id !== null ? [(int) $category->parent_id] : [];

        $childIds = Category::query()
            ->where('parent_id', $category->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$childIds]));
    }

    /** Locations are free text created on demand, so comparisons are case/whitespace insensitive. */
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = Str::lower(trim($value));

        return $normalised === '' ? null : $normalised;
    }
}
