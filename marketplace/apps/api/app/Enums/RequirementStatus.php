<?php

namespace App\Enums;

/**
 * Workflow status for a customer Requirement. Mirrors the ListingStatus
 * pattern (a single system_status field driving the lifecycle), but this
 * models a customer-facing "want" rather than a business-facing catalog
 * entry — matching/quotes/orders are later phases and only ever read this
 * status, never write it directly from client input.
 */
enum RequirementStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Matching = 'matching';
    case ReceivingQuotes = 'receiving_quotes';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Matching => 'Matching',
            self::ReceivingQuotes => 'Receiving Quotes',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }
}
