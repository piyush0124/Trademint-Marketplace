<?php

namespace App\Exceptions\Ai;

use App\Enums\AiRequestStatus;
use RuntimeException;

/**
 * Base for every AI-layer failure.
 *
 * Two things every subclass carries: a machine-readable status for the request
 * ledger, and a message safe to show a user. Provider errors routinely contain
 * request ids, internal hostnames and occasionally a truncated key — none of which
 * belongs in an API response, so the user-facing text is written by us and the
 * provider's own words stay in the log.
 */
abstract class AiException extends RuntimeException
{
    abstract public function requestStatus(): AiRequestStatus;

    /** Text safe to return to the caller. */
    abstract public function userMessage(): string;

    /** HTTP status the API should answer with. */
    public function httpStatus(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return $this->requestStatus()->value;
    }
}
