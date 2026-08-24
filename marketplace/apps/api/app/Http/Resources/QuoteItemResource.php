<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One quote line. All monetary values are emitted as fixed-point decimal
 * strings, exactly as stored, so no consumer re-derives them in floating point.
 */
class QuoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'quote_item',
            'attributes' => [
                'sort_order' => (int) $this->sort_order,
                'description' => $this->description,
                'item_type' => $this->item_type->value,
                'item_type_label' => $this->item_type->label(),
                // What catalogue entry this line came from, when it came from one.
                'reference_type' => $this->reference_type,
                'reference_id' => $this->reference_id !== null ? (int) $this->reference_id : null,
                'quantity' => $this->quantity,
                'unit' => $this->unit,
                'unit_price' => $this->unit_price,
                'tax_rate' => $this->tax_rate,
                'tax_amount' => $this->tax_amount,
                'discount_amount' => $this->discount_amount,
                'subtotal' => $this->subtotal,
                'total' => $this->total,
            ],
        ];
    }
}
