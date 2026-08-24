<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'lead',
            'attributes' => [
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'message' => $this->message,
                'status' => $this->status->value,
                'contacted_at' => $this->contacted_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
            ],
            'relationships' => [
                'listing' => new ListingResource($this->whenLoaded('listing')),
            ],
        ];
    }
}
