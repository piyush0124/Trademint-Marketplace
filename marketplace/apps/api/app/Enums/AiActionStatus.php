<?php

namespace App\Enums;

/**
 * Lifecycle of an AI-proposed write action.
 *
 * The only path to `Executed` runs through `Confirmed`, and confirmation is a
 * separate authenticated request from the user — never something the model can
 * assert about itself.
 */
enum AiActionStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Executed = 'executed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PendingConfirmation => 'Awaiting your confirmation',
            self::Confirmed => 'Confirmed',
            self::Executed => 'Done',
            self::Rejected => 'Declined',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Executed, self::Rejected, self::Failed, self::Expired], true);
    }
}
