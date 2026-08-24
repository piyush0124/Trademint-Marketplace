<?php

namespace App\Services\Ai\Extraction;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns whatever the model said the category was into a real `categories` row — or
 * into an honest "ask the user".
 *
 * This is the guard that makes "AI must not invent categories" true. Nothing here can
 * create a category; the only outcomes are a row that already existed or null. A model
 * that returns "Office Interior Design" when no such row exists produces a
 * clarification, not a new taxonomy.
 *
 * Resolution is tried cheapest-first and each step is exact or near-exact. Fuzzy
 * matching is deliberately conservative: a wrong-but-confident category sends the
 * requirement to entirely the wrong suppliers, which is worse than one extra question.
 */
class CategoryResolver
{
    /** Similarity below which a fuzzy match is not trusted without asking. */
    private const FUZZY_THRESHOLD = 82;

    /**
     * @return array{category: Category|null, confidence: float, candidates: array<int, Category>, reason: string}
     */
    public function resolve(?string $value, ?string $freeText = null): array
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value !== '') {
            // 1. Exact slug. This is what the model is asked for, so it is the
            //    common path and costs one indexed lookup.
            $exact = Category::query()->where('is_active', true)->where('slug', $value)->first();

            if ($exact !== null) {
                return $this->hit($exact, 1.0, 'Matched category slug exactly.');
            }

            // 2. Exact name, case-insensitive. Models routinely return the label
            //    rather than the slug however firmly they are told otherwise.
            $byName = Category::query()->where('is_active', true)->whereRaw('lower(name) = ?', [mb_strtolower($value)])->first();

            if ($byName !== null) {
                return $this->hit($byName, 0.95, 'Matched category name exactly.');
            }

            // 3. Slugified name: "Office Interior" → office-interior.
            $bySlugified = Category::query()->where('is_active', true)->where('slug', Str::slug($value))->first();

            if ($bySlugified !== null) {
                return $this->hit($bySlugified, 0.9, 'Matched category after normalising the name.');
            }

            /*
             * 4. Containment, either direction.
             *
             * Needed because `similar_text` scores by length ratio, so a short but
             * highly informative input scores badly against a longer name: "web" versus
             * "Web Development" is only 46% similar and never clears the fuzzy
             * threshold, even though it is obviously relevant. Containment catches
             * exactly that shape — one clear hit resolves, several become a choice.
             */
            $contained = $this->containmentMatches($value);

            if ($contained->count() === 1) {
                return $this->hit($contained->first(), 0.8, sprintf('Only category matching "%s".', $value));
            }

            if ($contained->count() > 1) {
                return [
                    'category' => null,
                    'confidence' => 0.4,
                    'candidates' => $contained->take(5)->all(),
                    'reason' => sprintf('Several categories match "%s"; the buyer should choose.', $value),
                ];
            }

            // 5. Fuzzy, over names only — catches misspellings rather than short inputs.
            $fuzzy = $this->fuzzyMatch($value);

            if ($fuzzy !== null) {
                return $fuzzy;
            }
        }

        /*
         * Nothing matched on the category field. Fall back to keyword-scanning the
         * buyer's own words — a model that failed to name a category may still have
         * been handed "we need steel fabrication", and the word "fabrication" is
         * enough to shortlist real rows for the user to choose from.
         */
        $candidates = $this->candidatesFromText($freeText ?? $value);

        if ($candidates->count() === 1) {
            return $this->hit($candidates->first(), 0.7, 'Inferred from wording in the request.');
        }

        return [
            'category' => null,
            'confidence' => 0.0,
            'candidates' => $candidates->all(),
            'reason' => $candidates->isEmpty()
                ? 'No matching category exists on the marketplace.'
                : 'Several categories could fit; the buyer should choose.',
        ];
    }

    /**
     * The catalogue as a compact list for the extraction prompt.
     *
     * Sent as `slug — Name (Industry)` so the model has the exact string it must
     * return. Capped, because a 90-line list on every extraction is real token cost
     * and a long list measurably degrades selection accuracy. Industries come first
     * so the broad options survive the cap.
     */
    public function promptCatalogue(int $limit = 60): string
    {
        return Category::query()
            ->where('is_active', true)
            ->with('parent:id,name')
            ->orderByRaw('parent_id IS NOT NULL')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Category $category): string => sprintf(
                '%s — %s%s',
                $category->slug,
                $category->name,
                $category->parent !== null ? ' ('.$category->parent->name.')' : '',
            ))
            ->implode("\n");
    }

    /**
     * Categories whose name contains the input, or whose name the input contains.
     *
     * Both directions matter: "web" is contained in "Web Development", and "office
     * interior design work" contains "Interior Design". Shorter names first, so the more
     * general category leads when both a parent and a speciality match.
     *
     * Guarded at three characters — a two-letter input would match a third of the
     * taxonomy and produce a useless list.
     *
     * @return Collection<int, Category>
     */
    private function containmentMatches(string $value): Collection
    {
        $needle = mb_strtolower(trim($value));

        if (mb_strlen($needle) < 3) {
            return collect();
        }

        return Category::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'slug', 'parent_id'])
            ->filter(function (Category $category) use ($needle): bool {
                $name = mb_strtolower($category->name);

                return str_contains($name, $needle) || str_contains($needle, $name);
            })
            ->sortBy(fn (Category $category): int => mb_strlen($category->name))
            ->values();
    }

    /**
     * Longest-common-subsequence-ish similarity via PHP's own comparator.
     *
     * `similar_text` is adequate here and needs no dependency. It is applied only to
     * names, not slugs, because slug punctuation skews the percentage.
     *
     * @return array{category: Category|null, confidence: float, candidates: array<int, Category>, reason: string}|null
     */
    private function fuzzyMatch(string $value): ?array
    {
        $needle = mb_strtolower($value);

        $scored = Category::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'slug', 'parent_id'])
            ->map(function (Category $category) use ($needle): array {
                similar_text($needle, mb_strtolower($category->name), $percent);

                return ['category' => $category, 'percent' => $percent];
            })
            ->sortByDesc('percent')
            ->values();

        $best = $scored->first();

        if ($best === null || $best['percent'] < self::FUZZY_THRESHOLD) {
            return null;
        }

        // Two near-identical scores mean genuine ambiguity — "Web Design" versus
        // "Web Development" for the input "web". Asking beats coin-flipping.
        $runnerUp = $scored->get(1);

        if ($runnerUp !== null && abs($best['percent'] - $runnerUp['percent']) < 5) {
            return [
                'category' => null,
                'confidence' => 0.4,
                'candidates' => [$best['category'], $runnerUp['category']],
                'reason' => 'Two categories are equally close; the buyer should choose.',
            ];
        }

        return $this->hit(
            $best['category'],
            // Scaled from the similarity, capped below the exact-match tiers so a
            // fuzzy hit can never look as certain as an exact one.
            round(min(0.85, $best['percent'] / 100), 2),
            sprintf('Closest matching category to "%s".', $value),
        );
    }

    /**
     * Shortlist categories whose name words appear in the buyer's text.
     *
     * Words of three characters or fewer are skipped — "it" would otherwise match
     * half the sentence. Deliberately keyword-based rather than another model call:
     * this runs when extraction has already under-performed, and a second AI round
     * would add cost and latency for a list the user is about to be shown anyway.
     *
     * @return Collection<int, Category>
     */
    private function candidatesFromText(?string $text): Collection
    {
        if ($text === null || trim($text) === '') {
            return collect();
        }

        $haystack = mb_strtolower($text);

        return Category::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'slug', 'parent_id'])
            ->filter(function (Category $category) use ($haystack): bool {
                foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($category->name)) ?: [] as $word) {
                    if (mb_strlen($word) > 3 && str_contains($haystack, $word)) {
                        return true;
                    }
                }

                return false;
            })
            ->take(5)
            ->values();
    }

    /**
     * @return array{category: Category, confidence: float, candidates: array<int, Category>, reason: string}
     */
    private function hit(Category $category, float $confidence, string $reason): array
    {
        return ['category' => $category, 'confidence' => $confidence, 'candidates' => [], 'reason' => $reason];
    }
}
