<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'category',
            'attributes' => [
                'slug' => $this->slug,
                'name' => $this->name,
                'description' => $this->description,
                'is_active' => $this->is_active,
                // The hierarchy has to be visible to the client, otherwise the
                // frontend cannot tell an industry ("Construction") from a
                // speciality ("Construction — Steel Fabrication") and ends up
                // hardcoding a list of the ones it wants to show.
                'parent_id' => $this->parent_id,
                'parent_slug' => $this->whenLoaded('parent', fn () => $this->parent?->slug),
                'is_top_level' => $this->parent_id === null,
                // Only present when the caller asked for counts.
                'children_count' => $this->when(isset($this->children_count), fn () => (int) $this->children_count),
                'businesses_count' => $this->when(isset($this->businesses_count), fn () => (int) $this->businesses_count),
                'listings_count' => $this->when(isset($this->listings_count), fn () => (int) $this->listings_count),
            ],
        ];
    }
}
