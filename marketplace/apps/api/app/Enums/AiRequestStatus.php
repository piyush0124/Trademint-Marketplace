<?php

namespace App\Enums;

/**
 * Outcome of a single provider call.
 *
 * Failures are recorded, not swallowed. A provider that is timing out shows up as a
 * row here, which is how an operator finds out before users complain.
 */
enum AiRequestStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case QuotaExceeded = 'quota_exceeded';
    case InvalidResponse = 'invalid_response';
    case Disabled = 'disabled';

    public function isSuccess(): bool
    {
        return $this === self::Success;
    }
}
