<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A proposed write action, as shown at the confirmation prompt.
 *
 * `summary` is the server-composed description of what will actually happen — not the
 * model's account of it. `arguments` is exposed too, so a user who wants to check the
 * exact values before agreeing can, rather than taking a sentence on trust.
 */
class AiActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => 'ai_action',
            'attributes' => [
                'tool' => $this->tool,
                'summary' => $this->summary,
                'status' => $this->status->value,
                'status_label' => $this->status->label(),
                'is_confirmable' => $this->isConfirmable(),
                // Safe to show: these are the validated arguments the backend will
                // replay, and they contain no identifiers of other users.
                'arguments' => $this->arguments,
                'result' => $this->result,
                'failure_reason' => $this->failure_reason,
                'expires_at' => $this->expires_at?->toIso8601String(),
                'executed_at' => $this->executed_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }
}
