<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One notification class for every marketplace event.
 *
 * ---------------------------------------------------------------------------
 * WHY ONE CLASS AND NOT NINETEEN
 * ---------------------------------------------------------------------------
 * A class per event is the conventional Laravel shape and it would mean nineteen
 * near-identical files whose only real difference is a sentence of copy. The
 * wording would then be spread across nineteen places, which is how a product ends
 * up describing the same thing three different ways.
 *
 * Instead the *type* is data (see NotificationType) and the routing and wording
 * live in one auditable file, SendMarketplaceNotifications. The question "who gets
 * told what, and in what words" has exactly one answer to read.
 *
 * The trade-off is that `Notification::assertSentTo($user, XNotification::class)`
 * cannot distinguish kinds in tests, so assertions match on the payload's `type`
 * instead. That is a marginally longer assertion in exchange for a far smaller
 * surface, and the tests read no worse for it.
 *
 * ---------------------------------------------------------------------------
 * DATABASE CHANNEL ONLY, FOR NOW
 * ---------------------------------------------------------------------------
 * Deliberately in-app before email. In-app needs no deliverability work, no
 * unsubscribe handling and no reputation management, so it can ship now and be
 * correct. Adding `mail` later is a change to `via()` plus a per-type preference
 * check — the payload already carries everything an email would need.
 *
 * Emailing all nineteen types from day one would also be the fastest way to train
 * users to filter us into a folder, which would then bury the two or three that
 * genuinely matter.
 */
class MarketplaceNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $meta  Small contextual facts (amount, status).
     *                                      Never a full model or a request payload.
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
        public readonly array $meta = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The stored payload.
     *
     * Denormalised on purpose: the title and body are frozen at send time rather
     * than recomputed on read. A notification is a record of what someone was told,
     * and rendering it from live models would mean an old notification silently
     * rewrites itself when the underlying quote changes — or breaks outright when
     * the record is deleted.
     *
     * `url` is a frontend path, not an API endpoint, because the only consumer is
     * the SPA deciding where to navigate.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'importance' => $this->type->importance(),
            'icon' => $this->type->icon(),
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'meta' => $this->meta,
        ];
    }
}
