<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => 'ai_conversation',
            'attributes' => [
                'conversation_type' => $this->type->value,
                'conversation_type_label' => $this->type->label(),
                'title' => $this->title,
                'context' => $this->context,
                'message_count' => $this->when(isset($this->messages_count), fn () => (int) $this->messages_count),
                'last_message_at' => $this->last_message_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
            ],
            'relationships' => [
                'messages' => AiMessageResource::collection($this->whenLoaded('messages')),
                'actions' => AiActionResource::collection($this->whenLoaded('actions')),
            ],
        ];
    }
}
