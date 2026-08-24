<?php

namespace App\Enums;

/**
 * How soon the customer needs this fulfilled. Purely informational in
 * Phase 3 — later phases may use it to prioritize matching — but it's
 * captured now so it doesn't need a breaking schema change later.
 */
enum RequirementUrgency: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }
}
