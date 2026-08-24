<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'product',
            'attributes' => [
                'name' => $this->name,
                'description' => $this->description,
                'unit' => $this->unit,
                'min_order' => $this->min_order,
                'max_order' => $this->max_order,
                'capacity' => $this->capacity,
                'customization_available' => $this->customization_available,
                'is_active' => $this->is_active,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
            'relationships' => [
                'category' => new CategoryResource($this->whenLoaded('category')),
            ],
        ];
    }
}
