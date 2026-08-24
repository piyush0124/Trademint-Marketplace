<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A refund record: reference, amount, reason, status, date. */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => 'refund',
            'attributes' => [
                'refund_reference' => $this->refund_reference,
                'amount' => $this->amount,
                'currency' => $this->currency,
                'status' => $this->status->value,
                'status_label' => $this->status->label(),
                'reason' => $this->reason,
                'failure_reason' => $this->failure_reason,
                'processed_at' => $this->processed_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }
}
