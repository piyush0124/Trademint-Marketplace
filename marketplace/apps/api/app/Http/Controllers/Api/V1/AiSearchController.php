<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VerificationStatus;
use App\Exceptions\Ai\AiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Models\Category;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Search\SearchQueryTranslator;
use App\Services\Ai\Search\SearchSuggestionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 12 — natural-language supplier search.
 *
 * The AI translates the sentence into filters; this controller then runs the same
 * supplier query the public discovery page runs. Results are therefore identical to
 * what a buyer would get by setting those filters by hand — AI search cannot surface a
 * business the normal search would hide, and cannot invent one, because it never
 * touches the result set.
 *
 * If the provider is down the endpoint still answers: the query falls back to a plain
 * keyword search and says so in the response. Search is too important to fail because
 * an assistant is unavailable.
 */
class AiSearchController extends Controller
{
    public function __construct(
        private readonly SearchQueryTranslator $translator,
        private readonly SearchSuggestionService $suggestions,
        private readonly AiProviderManager $providers,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:'.(int) config('ai.limits.max_input_characters', 4000)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $limit = (int) ($validated['limit'] ?? 12);

        try {
            $translation = $this->translator->translate($validated['query'], $request->user());
        } catch (AiException $exception) {
            // Degrade to keyword search rather than returning an error. The buyer gets
            // results; they are just less precisely filtered.
            $translation = [
                'filters' => ['search' => $validated['query']],
                'interpretation' => ['keyword' => $validated['query']],
                'unmatched' => [],
                'used_ai' => false,
            ];
        }

        $businesses = $this->runSearch($translation['filters'], $limit);

        // Explanations are composed from the returned rows, not generated, so every
        // reason shown is a fact in the database.
        $explanations = $this->translator->explainResults(
            $translation['filters'],
            $businesses->map(fn (Business $business): array => [
                'slug' => $business->slug,
                'category' => $business->category?->name,
                'city' => $business->location?->city,
                'verified' => $business->verificationStatus() === VerificationStatus::Verified,
                'rating' => $business->rating,
                'review_count' => $business->review_count,
                'years_in_business' => $business->years_in_business,
            ])->all(),
            $translation['interpretation'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Search completed.',
            'data' => BusinessResource::collection($businesses),
            'meta' => [
                'total' => $businesses->count(),
                'query' => $validated['query'],
                // What the AI understood, so a buyer can correct it instead of
                // guessing why the results look wrong.
                'interpretation' => $translation['interpretation'],
                'filters_applied' => $translation['filters'],
                // Named honestly: things mentioned that could not become a filter.
                'not_applied' => $translation['unmatched'],
                'used_ai' => $translation['used_ai'],
                'explanations' => collect($explanations)->keyBy('slug')->map->reasons->all(),
                'fallback_notice' => $translation['used_ai']
                    ? null
                    : 'The assistant was unavailable, so this was searched as plain keywords.',
            ],
        ]);
    }

    /**
     * Type-ahead suggestions.
     *
     * Deliberately no AI call — see SearchSuggestionService. Also outside the AI
     * throttle in the routes, because it fires per keystroke and every suggestion comes
     * from the database rather than a provider.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'term' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Suggestions retrieved successfully.',
            'data' => $this->suggestions->suggest(
                (string) ($validated['term'] ?? ''),
                (int) ($validated['limit'] ?? 6),
            ),
            'meta' => [
                // Stated so the frontend knows these are free and instant.
                'source' => 'database',
                'ai_available' => $this->providers->isEnabled('search') && $this->providers->isAvailable(),
            ],
        ]);
    }

    /**
     * The supplier query.
     *
     * Mirrors BusinessController::index rather than reimplementing discovery: same
     * suspension rule, same category-subtree expansion, same city-or-service-area match,
     * same ordering. Divergence here would mean AI search and normal search disagreeing
     * about who exists.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Business>
     */
    private function runSearch(array $filters, int $limit)
    {
        $query = Business::query()
            ->notSuspended()
            ->with(['location:id,city,state', 'category:id,name,slug', 'serviceAreas:id,city'])
            ->withCount(['listings' => fn ($q) => $q->published()]);

        if (! empty($filters['category'])) {
            $category = Category::where('slug', $filters['category'])->first();

            if ($category !== null) {
                $ids = array_merge([$category->id], $category->children()->pluck('id')->all());

                $query->where(function ($q) use ($ids): void {
                    $q->whereIn('category_id', $ids)
                        ->orWhereHas('products', fn ($p) => $p->where('is_active', true)->whereIn('category_id', $ids))
                        ->orWhereHas('services', fn ($s) => $s->where('is_active', true)->whereIn('category_id', $ids));
                });
            }
        }

        if (! empty($filters['city'])) {
            $city = (string) $filters['city'];

            $query->where(function ($q) use ($city): void {
                $q->whereHas('location', fn ($l) => $l->where('city', 'like', "%$city%"))
                    ->orWhereHas('serviceAreas', fn ($l) => $l->where('city', 'like', "%$city%"));
            });
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];

            // Widened beyond name/about to the catalogue: "10 ton shipments" is far more
            // likely to appear in a service description than in a company name.
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('about', 'like', "%$search%")
                    ->orWhereHas('products', fn ($p) => $p->where('is_active', true)
                        ->where(fn ($i) => $i->where('name', 'like', "%$search%")->orWhere('description', 'like', "%$search%")))
                    ->orWhereHas('services', fn ($s) => $s->where('is_active', true)
                        ->where(fn ($i) => $i->where('name', 'like', "%$search%")->orWhere('description', 'like', "%$search%")));
            });
        }

        if (! empty($filters['verified_only'])) {
            $query->where('verification_status', VerificationStatus::Verified->value);
        }

        if (! empty($filters['min_rating'])) {
            $query->where('rating', '>=', (float) $filters['min_rating']);
        }

        if (! empty($filters['trade_type'])) {
            $query->where('trade_type', $filters['trade_type']);
        }

        return $query
            ->orderByRaw('verification_status = ? desc', [VerificationStatus::Verified->value])
            ->orderByRaw('rating IS NULL')
            ->orderByDesc('rating')
            ->limit($limit)
            ->get();
    }
}
