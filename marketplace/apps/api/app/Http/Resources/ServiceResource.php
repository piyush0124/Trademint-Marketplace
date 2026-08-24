<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'service',
            'attributes' => [
                'name' => $this->name,
                'description' => $this->description,
                'min_order' => $this->min_order,
                'estimated_delivery_time' => $this->estimated_delivery_time,
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
