<?php

namespace App\Services\Ai\Search;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Type-ahead suggestions for the search box.
 *
 * No AI call, and that is the design rather than a shortcut. Suggestions fire on every
 * keystroke: a model round trip would be too slow to be useful, would cost a request
 * per character, and would happily suggest "Drone Photography" when no supplier offers
 * it. Every suggestion here is a row that exists — a category, or a product or service
 * a real business has published — so clicking one always leads somewhere populated.
 *
 * The brief's example (typing "website" offering Website Development, E-commerce
 * Development, Website Maintenance, Web Design, SEO Services) works exactly as long as
 * those are real categories, and correctly returns fewer when they are not.
 */
class SearchSuggestionService
{
    /**
     * @return array{
     *     categories: array<int, array{slug: string, name: string, industry: string|null, suppliers: int}>,
     *     capabilities: array<int, array{label: string, category: string|null, type: string}>,
     *     cities: array<int, array{city: string, state: string|null}>
     * }
     */
    public function suggest(string $term, int $limit = 6): array
    {
        $term = trim($term);

        // Below two characters every suggestion is noise, so return the busiest
        // industries instead — a useful empty state rather than a random slice.
        if (mb_strlen($term) < 2) {
            return [
                'categories' => $this->popularIndustries($limit)->all(),
                'capabilities' => [],
                'cities' => [],
            ];
        }

        return [
            'categories' => $this->matchingCategories($term, $limit)->all(),
            'capabilities' => $this->matchingCapabilities($term, $limit)->all(),
            'cities' => $this->matchingCities($term, 4)->all(),
        ];
    }

    /**
     * Categories whose name or slug contains the term.
     *
     * Ordered by how many suppliers sit under them, so a term matching both a busy and
     * an empty category offers the busy one first. Sorting alphabetically would put a
     * category with no suppliers at the top and send the buyer to an empty page.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function matchingCategories(string $term, int $limit): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', "%$term%")->orWhere('slug', 'like', '%'.str_replace(' ', '-', $term).'%'))
            ->with('parent:id,name')
            ->withCount(['businesses' => fn ($q) => $q->notSuspended()])
            ->orderByDesc('businesses_count')
            ->orderByRaw('length(name)')
            ->limit($limit)
            ->get()
            ->map(fn (Category $category): array => [
                'slug' => $category->slug,
                'name' => $category->name,
                'industry' => $category->parent?->name,
                'suppliers' => (int) $category->businesses_count,
            ]);
    }

    /**
     * Products and services real businesses have published.
     *
     * This is what makes suggestions specific to the marketplace rather than generic.
     * "website" surfaces the actual service names suppliers offer, which is more useful
     * than a taxonomy label and is guaranteed to have someone behind it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function matchingCapabilities(string $term, int $limit): Collection
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('name', 'like', "%$term%")
            ->whereHas('business', fn ($q) => $q->notSuspended())
            ->with('category:id,name')
            // Distinct names: twenty suppliers offering "AMC" should be one suggestion.
            ->get(['id', 'name', 'category_id'])
            ->unique(fn (Product $product): string => mb_strtolower($product->name))
            ->take($limit)
            ->map(fn (Product $product): array => [
                'label' => $product->name,
                'category' => $product->category?->name,
                'type' => 'product',
            ]);

        $services = Service::query()
            ->where('is_active', true)
            ->where('name', 'like', "%$term%")
            ->whereHas('business', fn ($q) => $q->notSuspended())
            ->with('category:id,name')
            ->get(['id', 'name', 'category_id'])
            ->unique(fn (Service $service): string => mb_strtolower($service->name))
            ->take($limit)
            ->map(fn (Service $service): array => [
                'label' => $service->name,
                'category' => $service->category?->name,
                'type' => 'service',
            ]);

        return $products->concat($services)
            ->unique(fn (array $row): string => mb_strtolower($row['label']))
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function matchingCities(string $term, int $limit): Collection
    {
        return Location::query()
            ->where('city', 'like', "%$term%")
            ->select('city', 'state')
            ->selectRaw('count(distinct businesses.id) as suppliers')
            ->leftJoin('businesses', 'businesses.location_id', '=', 'locations.id')
            ->groupBy('city', 'state')
            ->orderByDesc('suppliers')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => ['city' => (string) $row->city, 'state' => $row->state]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function popularIndustries(int $limit): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->withCount(['businesses' => fn ($q) => $q->notSuspended()])
            ->orderByDesc('businesses_count')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->map(fn (Category $category): array => [
                'slug' => $category->slug,
                'name' => $category->name,
                'industry' => null,
                'suppliers' => (int) $category->businesses_count,
            ]);
    }
}
