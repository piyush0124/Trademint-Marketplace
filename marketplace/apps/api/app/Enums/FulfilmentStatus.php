<?php

namespace App\Enums;

/**
 * Progress of the actual work, tracked alongside OrderStatus (Phase 7).
 *
 * The two are related but not the same question: `status` is the order's
 * commercial state ("confirmed", "cancelled"), `fulfilment_status` is how far the
 * work itself has got. OrderService keeps them consistent — this is derived from
 * the order status rather than being a second thing for a user to set, which
 * avoids two state machines disagreeing.
 */
enum FulfilmentStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Ready = 'ready';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Ready => 'Ready',
            self::Dispatched => 'Dispatched',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** The fulfilment position implied by an order status. */
    public static function forOrderStatus(OrderStatus $status): self
    {
        return match ($status) {
            OrderStatus::Pending, OrderStatus::Confirmed => self::NotStarted,
            OrderStatus::Processing, OrderStatus::InProgress, OrderStatus::Disputed => self::InProgress,
            OrderStatus::Ready => self::Ready,
            OrderStatus::Dispatched => self::Dispatched,
            OrderStatus::Delivered => self::Delivered,
            OrderStatus::Completed => self::Completed,
            OrderStatus::Cancelled => self::Cancelled,
        };
    }
}
