<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One timeline entry.
 *
 * `author_party` is exposed ("customer", "business", "system") but the author's
 * *identity* is not: a business must not learn who the customer is from the
 * conversation, which is the same rule RequirementResource and EnquiryResource
 * already apply.
 */
class NegotiationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'negotiation_message',
            'attributes' => [
                'entry_type' => $this->entry_type,
                'author_party' => $this->author_party,
                'body' => $this->body,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
            'relationships' => [
                // Which offer this entry was about, so the timeline can show the
                // figures alongside the message.
                'revision' => new QuoteRevisionResource($this->whenLoaded('revision')),
            ],
        ];
    }
}
