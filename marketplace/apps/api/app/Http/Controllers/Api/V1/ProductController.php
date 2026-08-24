<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /** Public: active products for one business's catalog page. */
    public function indexForBusiness(string $slug): JsonResponse
    {
        $products = Product::query()
            ->whereHas('business', fn ($q) => $q->where('slug', $slug))
            ->where('is_active', true)
            ->with('category')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data' => ProductResource::collection($products),
        ]);
    }

    /** Owner's own products, including inactive ones — for the dashboard. */
    public function mine(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'You do not have permission to perform this action.');

        $products = $request->user()->business?->products()->with('category')->latest()->get() ?? collect();

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data' => ProductResource::collection($products),
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $business = $request->user()->business;
        $category = isset($request->validated()['category_slug'])
            ? Category::where('slug', $request->validated('category_slug'))->first()
            : null;

        $product = $business->products()->create([
            ...$request->safe()->except(['category_slug']),
            'category_id' => $category?->id,
            'is_active' => $request->validated('is_active') ?? true,
            'customization_available' => $request->validated('customization_available') ?? false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product created.',
            'data' => new ProductResource($product->load('category')),
        ], 201);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $data = $request->safe()->except(['category_slug']);

        if (array_key_exists('category_slug', $request->validated())) {
            $category = $request->validated('category_slug')
                ? Category::where('slug', $request->validated('category_slug'))->first()
                : null;
            $data['category_id'] = $category?->id;
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Product updated.',
            'data' => new ProductResource($product->fresh('category')),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted.',
            'data' => null,
        ]);
    }
}
