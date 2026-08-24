<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->attributeValues
            ->mapWithKeys(fn ($value) => [$value->definition->key => $this->castAttributeValue($value->value, $value->definition->type->value)])
            ->all();

        /*
         * The same values again, but carrying their database-defined label and sort
         * order.
         *
         * The flat `attributes` bag above loses the label, which forced the frontend
         * to keep a hardcoded `{ property_type: 'Property Type', bedrooms: 'Bedrooms' }`
         * map — real-estate-only by construction, and silently blank for every other
         * industry. With the label travelling alongside the value, any category's
         * attributes render correctly with no frontend change.
         */
        $attributeDetails = $this->attributeValues
            ->sortBy(fn ($value) => $value->definition->sort_order)
            ->map(fn ($value) => [
                'key' => $value->definition->key,
                'label' => $value->definition->label,
                'type' => $value->definition->type->value,
                'value' => $this->castAttributeValue($value->value, $value->definition->type->value),
            ])
            ->values()
            ->all();

        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->id === $this->user_id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();

        return [
            // `id` stays the uuid: it is the stable identifier every existing client
            // and route already uses. The slug is additive and is what links should
            // now be built from.
            'id' => $this->uuid,
            'type' => 'listing',
            'attributes' => [
                /*
                 * The keyword-bearing URL segment. Links and canonical tags should use
                 * this; `id` above remains the uuid for API identity.
                 *
                 * Nullable for a titleless draft, so callers must fall back to `id`.
                 */
                'slug' => $this->slug,
                'title' => $this->title,
                'description' => $this->description,
                'price' => $this->price,
                'price_max' => $this->price_max,
                'unit' => $this->unit,
                'min_order_quantity' => $this->min_order_quantity,
                'currency' => $this->currency,
                'system_status' => $this->status->value,
                'availability_status' => $this->availability_status->value,
                'rejection_reason' => $this->when($isOwner || $isAdmin, $this->rejection_reason),
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'published_at' => $this->published_at?->toIso8601String(),
                'rejected_at' => $this->rejected_at?->toIso8601String(),
                'archived_at' => $this->archived_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
                'is_editable' => $isOwner ? $this->isEditableByOwner() : false,
                'attributes' => $attributes,
                'attribute_details' => $attributeDetails,
                'leads_count' => $this->when(isset($this->leads_count), fn () => (int) $this->leads_count),
                'new_leads_count' => $this->when(isset($this->new_leads_count), fn () => (int) $this->new_leads_count),
            ],
            'relationships' => [
                'category' => new CategoryResource($this->whenLoaded('category')),
                'location' => new LocationResource($this->whenLoaded('location')),
                'user' => new UserResource($this->whenLoaded('user')),
                'business' => new BusinessResource($this->whenLoaded('business')),
                'media' => MediaResource::collection($this->whenLoaded('media')),
            ],
        ];
    }

    private function castAttributeValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => $value === '1' || $value === 'true',
            default => $value,
        };
    }
}
