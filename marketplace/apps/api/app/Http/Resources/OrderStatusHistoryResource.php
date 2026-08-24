<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One immutable lifecycle entry.
 *
 * Exposes which *party* made the change, not the individual's identity — the same
 * privacy rule the rest of the marketplace follows, so a business never learns the
 * customer's name from an audit trail.
 */
class OrderStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'order_status_history',
            'attributes' => [
                'from_status' => $this->from_status?->value,
                'from_status_label' => $this->from_status?->label(),
                'to_status' => $this->to_status->value,
                'to_status_label' => $this->to_status->label(),
                'changed_by_party' => $this->changed_by_party,
                'note' => $this->note,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }
}
