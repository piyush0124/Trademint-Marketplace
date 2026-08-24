<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreServiceRequest;
use App\Http\Requests\Api\V1\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Category;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /** Public: active services for one business's catalog page. */
    public function indexForBusiness(string $slug): JsonResponse
    {
        $services = Service::query()
            ->whereHas('business', fn ($q) => $q->where('slug', $slug))
            ->where('is_active', true)
            ->with('category')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Services retrieved successfully.',
            'data' => ServiceResource::collection($services),
        ]);
    }

    /** Owner's own services, including inactive ones — for the dashboard. */
    public function mine(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403, 'You do not have permission to perform this action.');

        $services = $request->user()->business?->services()->with('category')->latest()->get() ?? collect();

        return response()->json([
            'success' => true,
            'message' => 'Services retrieved successfully.',
            'data' => ServiceResource::collection($services),
        ]);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $this->authorize('create', Service::class);

        $business = $request->user()->business;
        $category = isset($request->validated()['category_slug'])
            ? Category::where('slug', $request->validated('category_slug'))->first()
            : null;

        $service = $business->services()->create([
            ...$request->safe()->except(['category_slug']),
            'category_id' => $category?->id,
            'is_active' => $request->validated('is_active') ?? true,
            'customization_available' => $request->validated('customization_available') ?? false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service created.',
            'data' => new ServiceResource($service->load('category')),
        ], 201);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $this->authorize('update', $service);

        $data = $request->safe()->except(['category_slug']);

        if (array_key_exists('category_slug', $request->validated())) {
            $category = $request->validated('category_slug')
                ? Category::where('slug', $request->validated('category_slug'))->first()
                : null;
            $data['category_id'] = $category?->id;
        }

        $service->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Service updated.',
            'data' => new ServiceResource($service->fresh('category')),
        ]);
    }

    public function destroy(Service $service): JsonResponse
    {
        $this->authorize('delete', $service);

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service deleted.',
            'data' => null,
        ]);
    }
}
