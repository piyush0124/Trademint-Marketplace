<?php

namespace App\Http\Resources;

use App\Models\AiMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One transcript turn.
 *
 * Tool messages are included in the transcript but their raw JSON payload is not
 * exposed — the UI shows *which* tools ran (grounding, so a user can see the answer
 * came from their data) without dumping the retrieved records back into the page.
 */
class AiMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isTool = $this->role === AiMessage::ROLE_TOOL;

        return [
            'id' => $this->id,
            'type' => 'ai_message',
            'attributes' => [
                'role' => $this->role,
                // A tool turn is machinery, not conversation; the frontend renders it
                // as a "checked your quotes" note rather than a chat bubble.
                'content' => $isTool ? null : $this->content,
                'tool' => $isTool ? ($this->metadata['tool'] ?? null) : null,
                'tool_ok' => $isTool ? ($this->metadata['ok'] ?? null) : null,
                // Which tools produced the facts in this reply.
                'tools_used' => $this->groundingTools(),
                // Write actions proposed on this turn, awaiting confirmation.
                'pending_actions' => array_values((array) ($this->metadata['pending_actions'] ?? [])),
                'model' => $this->model,
                'provider' => $this->provider,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }
}
