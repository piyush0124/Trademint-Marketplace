<?php

namespace App\Services\Ai\Search;

use App\Exceptions\Ai\AiException;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Ai\Extraction\AmountNormaliser;
use App\Services\Ai\Extraction\CategoryResolver;
use App\Services\Ai\Extraction\LocationResolver;
use Illuminate\Support\Str;

/**
 * Phase 12 — turns "verified logistics companies near Noida that can handle 10 ton
 * shipments" into the filter set the existing supplier query already understands.
 *
 * The AI never searches. It reads the sentence and proposes filters; the marketplace's
 * own `GET /businesses` query does the work. That division matters for two reasons:
 * results are identical to what the normal search would return (so AI search cannot
 * surface a business the public page would hide), and a provider outage degrades to
 * plain keyword search rather than no search.
 *
 * Every proposed filter is validated — category against the categories table, city
 * against locations, numbers through the deterministic parser — so an invented value
 * becomes an ignored filter, never a broken query.
 */
class SearchQueryTranslator
{
    public function __construct(
        private readonly AiService $ai,
        private readonly CategoryResolver $categories,
        private readonly LocationResolver $locations,
        private readonly AmountNormaliser $amounts,
    ) {}

    /**
     * @return array{
     *     filters: array<string, mixed>,
     *     interpretation: array<string, mixed>,
     *     unmatched: array<int, string>,
     *     used_ai: bool
     * }
     */
    public function translate(string $query, ?User $user = null): array
    {
        $raw = $this->askModel($query, $user);
        $usedAi = $raw !== [];

        // Falls back to treating the whole sentence as a keyword when the model gave
        // nothing usable. Worse search, but still search.
        if (! $usedAi) {
            return [
                'filters' => ['search' => trim($query)],
                'interpretation' => ['keyword' => trim($query)],
                'unmatched' => [],
                'used_ai' => false,
            ];
        }

        $category = $this->categories->resolve(
            is_string($raw['category'] ?? null) ? $raw['category'] : null,
            $query,
        );

        $location = $this->locations->resolve(
            is_string($raw['city'] ?? null) ? $raw['city'] : null,
            is_string($raw['state'] ?? null) ? $raw['state'] : null,
        );

        $filters = [];
        $interpretation = [];
        $unmatched = [];

        if ($category['category'] !== null) {
            $filters['category'] = $category['category']->slug;
            $interpretation['category'] = $category['category']->name;
        } elseif (! empty($raw['category'])) {
            // Reported rather than silently dropped, so the UI can say "I could not
            // match 'drone photography' to a category".
            $unmatched[] = sprintf('category "%s"', (string) $raw['category']);
        }

        /*
         * City is passed through even when the location table has no such row. The
         * supplier query matches city text against a business's own city *and* its
         * declared service areas, so a city that exists as a service area but not as a
         * Location row still finds suppliers. Dropping the filter would silently widen
         * the search to all of India, which is worse than returning nothing.
         */
        if ($location['city'] !== null) {
            $filters['city'] = $location['city'];
            $interpretation['city'] = $location['city'];

            if (! $location['exists_in_database']) {
                $interpretation['city_note'] = 'No supplier is registered in this city yet.';
            }
        }

        if (! empty($raw['verified_only'])) {
            $filters['verified_only'] = true;
            $interpretation['verified_only'] = true;
        }

        $minRating = $this->rating($raw['min_rating'] ?? null);

        if ($minRating !== null) {
            $filters['min_rating'] = $minRating;
            $interpretation['min_rating'] = $minRating;
        }

        if (in_array($raw['trade_type'] ?? null, ['b2b', 'b2c', 'both'], true)) {
            $filters['trade_type'] = $raw['trade_type'];
            $interpretation['trade_type'] = $raw['trade_type'];
        }

        // Keywords carry the part of the request no filter can express — "10 ton
        // shipments", "304 grade", "ISO certified". Kept so the text search still
        // narrows on capability.
        $keywords = $this->keywords($raw['keywords'] ?? null, $query);

        if ($keywords !== null) {
            $filters['search'] = $keywords;
            $interpretation['keyword'] = $keywords;
        }

        /*
         * Capacity and budget are extracted and reported but not applied as filters,
         * because the supplier query has no column for either. Saying so is more honest
         * than quietly ignoring half the question — the UI surfaces them as
         * "mentioned, not filtered" and the assistant can ask about them.
         */
        $capacity = is_string($raw['capacity'] ?? null) && trim($raw['capacity']) !== ''
            ? trim($raw['capacity'])
            : null;

        if ($capacity !== null) {
            $interpretation['capacity_mentioned'] = $capacity;
        }

        $budget = $this->amounts->normalise($raw['budget_text'] ?? null);

        if ($budget !== null) {
            $interpretation['budget_mentioned'] = $budget;
        }

        return [
            'filters' => $filters,
            'interpretation' => $interpretation,
            'unmatched' => $unmatched,
            'used_ai' => true,
        ];
    }

    /**
     * Explain a result set using only what the search returned.
     *
     * No AI call: the explanation is composed from the filters that were applied and
     * the fields on each row. That makes it impossible to explain a match with a fact
     * the database does not hold, which is the failure mode a generated explanation
     * invites — "they specialise in cold-chain logistics" is exactly the kind of
     * plausible sentence a model produces from nothing.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, array<string, mixed>>  $businesses
     * @return array<int, array{slug: string, reasons: array<int, string>}>
     */
    public function explainResults(array $filters, array $businesses, array $interpretation = []): array
    {
        return collect($businesses)->map(function (array $business) use ($filters, $interpretation): array {
            $reasons = [];

            if (isset($interpretation['category']) && ! empty($business['category'])) {
                $reasons[] = sprintf('Works in %s', $business['category']);
            }

            if (isset($filters['city']) && ! empty($business['city'])) {
                $reasons[] = strcasecmp((string) $business['city'], (string) $filters['city']) === 0
                    ? sprintf('Based in %s', $business['city'])
                    : sprintf('Serves %s from %s', $filters['city'], $business['city']);
            }

            if (! empty($business['verified'])) {
                $reasons[] = 'Platform-verified supplier';
            }

            if (isset($business['rating']) && $business['rating'] !== null) {
                $reasons[] = sprintf(
                    'Rated %s from %d %s',
                    number_format((float) $business['rating'], 1),
                    (int) ($business['review_count'] ?? 0),
                    ((int) ($business['review_count'] ?? 0)) === 1 ? 'review' : 'reviews',
                );
            } else {
                // Stated plainly rather than omitted. A buyer choosing between an
                // unrated and a rated supplier should see the difference.
                $reasons[] = 'No ratings yet';
            }

            if (! empty($business['years_in_business'])) {
                $reasons[] = sprintf('%d years in business', (int) $business['years_in_business']);
            }

            return ['slug' => (string) ($business['slug'] ?? ''), 'reasons' => $reasons];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function askModel(string $query, ?User $user): array
    {
        $schema = [
            'category' => 'string — one category slug from the list, or null',
            'city' => 'string — city or place named, or null',
            'state' => 'string — state, or null',
            'keywords' => 'string — the capability words that are not covered by the other fields, or null',
            'verified_only' => 'boolean — true only if the user explicitly asked for verified suppliers',
            'min_rating' => 'number — 1 to 5, only if the user asked about ratings',
            'trade_type' => 'string — b2b, b2c or both, only if clearly implied',
            'capacity' => 'string — any scale or capacity requirement, e.g. "10 ton shipments", or null',
            'budget_text' => 'string — any budget mentioned, exactly as written, or null',
        ];

        try {
            $decoded = $this->ai->extract(
                input: $query,
                schema: $schema,
                user: $user,
                feature: 'search',
                instructions: <<<PROMPT
                Convert this supplier search into filters. Only set a field the user actually
                implied — do not add filters to be helpful. verified_only must stay false unless
                they asked for verified or trusted suppliers.

                CATEGORY: choose a slug from this list, or null. Never invent one.
                {$this->categories->promptCatalogue(40)}

                LANGUAGE: the query may be English, Hindi or mixed. Return values in English.
                PROMPT,
            );
        } catch (AiException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function rating(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $rating = (float) $value;

        // Out-of-range means the model guessed; ignoring beats clamping, which would
        // invent a filter the user never asked for.
        return $rating >= 1 && $rating <= 5 ? round($rating, 1) : null;
    }

    private function keywords(mixed $value, string $fallbackQuery): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return Str::limit(trim($value), 120, '');
        }

        // No keywords extracted and no other filter would be a search for everything,
        // so the caller decides — this returns null and `translate()` leaves `search`
        // unset only when other filters exist.
        return null;
    }
}
