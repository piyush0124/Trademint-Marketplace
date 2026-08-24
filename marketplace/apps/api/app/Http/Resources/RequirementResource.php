<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwnerCustomer = $viewer !== null && $viewer->id === $this->customer_id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();

        return [
            'id' => $this->uuid,
            'type' => 'requirement',
            'attributes' => [
                'title' => $this->title,
                'description' => $this->description,
                'quantity' => $this->quantity,
                'unit' => $this->unit,
                'budget_min' => $this->budget_min,
                'budget_max' => $this->budget_max,
                'required_date' => $this->required_date?->toDateString(),
                'urgency' => $this->urgency?->value,
                'status' => $this->status->value,
                'published_at' => $this->published_at?->toIso8601String(),
                'closed_at' => $this->closed_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
                'is_editable' => $isOwnerCustomer ? $this->isEditableByCustomer() : false,
                'is_cancellable' => $isOwnerCustomer ? $this->isCancellable() : false,
            ],
            'relationships' => [
                'category' => new CategoryResource($this->whenLoaded('category')),
                'location' => new LocationResource($this->whenLoaded('location')),
                // Only the owning customer or an admin ever sees who raised it —
                // a business browsing publicly-visible requirements gets the
                // requirement itself, not the customer's identity.
                'customer' => $this->when(
                    ($isOwnerCustomer || $isAdmin) && $this->relationLoaded('customer'),
                    fn () => new UserResource($this->customer)
                ),
            ],
        ];
    }
}
