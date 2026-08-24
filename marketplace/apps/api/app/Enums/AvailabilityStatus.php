<?php

namespace App\Enums;

/**
 * Business availability of a listing — independent of the moderation
 * (system) status. Example: a listing can be system_status=Published
 * while availability_status=Sold.
 */
enum AvailabilityStatus: string
{
    case Available = 'available';
    case Sold = 'sold';
    case Rented = 'rented';
    case Expired = 'expired';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Sold => 'Sold',
            self::Rented => 'Rented',
            self::Expired => 'Expired',
            self::Inactive => 'Inactive',
        };
    }
}
