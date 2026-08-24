<?php

namespace App\Enums;

/**
 * Admin-controlled verification state of a business (Phase 9).
 *
 * A business can *request* verification and upload documents; it can never set
 * this field. Only an admin moves it, and every move is audited.
 */
enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Not verified',
            self::Pending => 'Verification pending',
            self::Verified => 'Verified',
            self::Rejected => 'Verification rejected',
            self::Suspended => 'Suspended',
        };
    }

    /** Trusted enough to be shown as verified to customers. */
    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    /**
     * A suspended business stays visible in its existing commercial threads but
     * must not be presented as a viable new supplier.
     */
    public function isSuspended(): bool
    {
        return $this === self::Suspended;
    }

    /** States from which a business may (re)apply. */
    public function allowsRequest(): bool
    {
        return in_array($this, [self::Unverified, self::Rejected], true);
    }
}
