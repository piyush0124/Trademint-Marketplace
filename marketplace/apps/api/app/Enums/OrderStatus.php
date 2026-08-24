<?php

namespace App\Enums;

/**
 * Generic order lifecycle (Phase 7).
 *
 * Deliberately industry-neutral and deliberately *skippable*: a legal
 * consultation goes pending → confirmed → in_progress → completed and never
 * touches "dispatched", while a steel order may use every state. The transition
 * map below therefore allows forward jumps rather than forcing every order
 * through a chain designed for physical goods.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending confirmation',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Processing',
            self::InProgress => 'In progress',
            self::Ready => 'Ready',
            self::Dispatched => 'Dispatched',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Disputed => 'Disputed',
        };
    }

    /** Nothing further happens to the order. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** The work is underway or done — cancelling now needs a dispute, not a button. */
    public function isUnderway(): bool
    {
        return in_array($this, [self::Processing, self::InProgress, self::Ready, self::Dispatched, self::Delivered], true);
    }

    /**
     * Legal next states. Forward-only apart from dispute resolution, which is
     * admin-driven (Phase 10 builds on it).
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::InProgress, self::Ready, self::Dispatched, self::Delivered, self::Cancelled],
            self::Processing, self::InProgress => [self::Ready, self::Dispatched, self::Delivered, self::Completed, self::Cancelled, self::Disputed],
            self::Ready => [self::Dispatched, self::Delivered, self::Completed, self::Cancelled, self::Disputed],
            self::Dispatched => [self::Delivered, self::Completed, self::Disputed],
            self::Delivered => [self::Completed, self::Disputed],
            self::Completed => [self::Disputed],
            self::Disputed => [self::Completed, self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * States a business may set while fulfilling. The customer confirms completion
     * and may cancel early; an admin may force a dispute outcome.
     *
     * `Disputed` is included because either party may raise a dispute — a business
     * chasing an unpaid advance has the same right to escalate as a customer chasing
     * a late delivery (Phase 10).
     *
     * @return array<int, self>
     */
    public static function businessControlled(): array
    {
        return [self::Confirmed, self::Processing, self::InProgress, self::Ready, self::Dispatched, self::Delivered, self::Cancelled, self::Disputed];
    }

    /** @return array<int, self> */
    public static function customerControlled(): array
    {
        return [self::Completed, self::Cancelled, self::Disputed];
    }
}
