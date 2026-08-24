<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Listing;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    /** Upload one image to a listing. Owner may only do this while the listing is editable. */
    public function store(StoreMediaRequest $request, Listing $listing): JsonResponse
    {
        $this->authorize('update', $listing);

        $path = $request->file('file')->store('listings/'.$listing->uuid, 'public');

        $media = Media::create([
            'listing_id' => $listing->id,
            'disk' => 'public',
            'path' => $path,
            'filename' => $request->file('file')->getClientOriginalName(),
            'mime_type' => $request->file('file')->getClientMimeType(),
            'size' => $request->file('file')->getSize(),
            'sort_order' => $listing->media()->max('sort_order') + 1,
            'is_primary' => $listing->media()->count() === 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded.',
            'data' => new MediaResource($media),
        ], 201);
    }

    public function destroy(Listing $listing, Media $media): JsonResponse
    {
        $this->authorize('update', $listing);
        $this->assertBelongsToListing($listing, $media);

        Storage::disk($media->disk)->delete($media->path);
        $wasPrimary = $media->is_primary;
        $media->delete();

        if ($wasPrimary) {
            $next = $listing->media()->orderBy('sort_order')->first();
            $next?->update(['is_primary' => true]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image removed.',
            'data' => null,
        ]);
    }

    /** Body: { "order": [mediaId, mediaId, ...] } */
    public function reorder(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('update', $listing);

        $order = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ])['order'];

        foreach ($order as $index => $mediaId) {
            Media::where('id', $mediaId)->where('listing_id', $listing->id)->update(['sort_order' => $index]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image order updated.',
            'data' => MediaResource::collection($listing->media()->get()),
        ]);
    }

    public function setPrimary(Listing $listing, Media $media): JsonResponse
    {
        $this->authorize('update', $listing);
        $this->assertBelongsToListing($listing, $media);

        $listing->media()->update(['is_primary' => false]);
        $media->update(['is_primary' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Primary image updated.',
            'data' => MediaResource::collection($listing->media()->get()),
        ]);
    }

    private function assertBelongsToListing(Listing $listing, Media $media): void
    {
        if ($media->listing_id !== $listing->id) {
            throw ValidationException::withMessages([
                'media' => ['This image does not belong to the listing.'],
            ]);
        }
    }
}
