<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryAttributeDefinitionResource;
use App\Http\Resources\CategoryResource;
use App\Models\Business;
use App\Models\Category;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CategoryController extends Controller
{
    /**
     * The active category tree.
     *
     * `filter[top_level]=1` returns industries only, and `with_counts=1` adds how
     * many suppliers and published listings sit under each one *including its
     * children* — which is what lets the landing page rank industries by real
     * activity instead of shipping a hand-picked list of four.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->boolean('filter.top_level')) {
            $query->whereNull('parent_id');
        }

        if ($request->filled('filter.parent')) {
            $parent = Category::where('slug', $request->input('filter.parent'))->first();
            $query->where('parent_id', $parent?->id ?? 0);
        }

        $categories = $query->get();

        if ($request->boolean('with_counts')) {
            $this->attachCounts($categories);
        }

        return response()->json([
            'success' => true,
            'message' => 'Categories retrieved successfully.',
            'data' => CategoryResource::collection($categories),
        ]);
    }

    /**
     * Roll subtree counts onto each category in two queries rather than two per
     * row. A parent's count includes its children, because a buyer clicking
     * "Construction" expects the steel fabricators underneath it.
     *
     * @param  Collection<int, Category>  $categories
     */
    private function attachCounts(Collection $categories): void
    {
        $childrenByParent = Category::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $businessCounts = Business::query()
            ->notSuspended()
            ->selectRaw('category_id, count(*) as aggregate')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id');

        $listingCounts = Listing::query()
            ->published()
            ->selectRaw('category_id, count(*) as aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id');

        foreach ($categories as $category) {
            $ids = collect([$category->id])
                ->merge(($childrenByParent[$category->id] ?? collect())->pluck('id'))
                ->all();

            $category->children_count = count($ids) - 1;
            $category->businesses_count = collect($ids)->sum(fn (int $id) => (int) ($businessCounts[$id] ?? 0));
            $category->listings_count = collect($ids)->sum(fn (int $id) => (int) ($listingCounts[$id] ?? 0));
        }
    }

    public function attributeDefinitions(string $slug): JsonResponse
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Attribute definitions retrieved successfully.',
            'data' => CategoryAttributeDefinitionResource::collection(
                $category->attributeDefinitions
            ),
        ]);
    }
}
