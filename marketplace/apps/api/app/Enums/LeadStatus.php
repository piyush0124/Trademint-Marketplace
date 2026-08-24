<?php

namespace App\Enums;

/**
 * CRM pipeline status for a lead — separate from the listing's own
 * system/availability status, same pattern as ListingStatus/AvailabilityStatus.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Converted = 'converted';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Converted => 'Converted',
            self::Lost => 'Lost',
        };
    }
}
