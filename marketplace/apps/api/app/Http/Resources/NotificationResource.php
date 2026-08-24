<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 *
 * Flattens the stored `data` bag into the resource so the client reads
 * `attributes.title` rather than `attributes.data.title`.
 *
 * The payload was frozen at send time (see MarketplaceNotification::toArray), so
 * nothing here touches a live model. That is what makes a notification about a
 * deleted quote still render correctly instead of throwing.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'type' => 'notification',
            'id' => $this->id,
            'attributes' => [
                'notification_type' => $data['type'] ?? null,
                'importance' => $data['importance'] ?? 'info',
                'icon' => $data['icon'] ?? 'chat',
                'title' => $data['title'] ?? '',
                'body' => $data['body'] ?? '',
                // A frontend path, not an API endpoint — the only consumer is the SPA
                // deciding where to navigate.
                'url' => $data['url'] ?? null,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'meta' => $data['meta'] ?? [],
                'is_read' => $this->read_at !== null,
                'read_at' => $this->read_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
            ],
        ];
    }
}
