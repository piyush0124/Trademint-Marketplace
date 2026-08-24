<?php

namespace App\Exceptions\Ai;

use App\Enums\AiRequestStatus;

/** The user has spent their daily AI allowance. */
class AiQuotaExceededException extends AiException
{
    public function __construct(private readonly int $limit)
    {
        parent::__construct(sprintf('Daily AI request limit of %d reached.', $limit));
    }

    public function requestStatus(): AiRequestStatus
    {
        return AiRequestStatus::QuotaExceeded;
    }

    public function httpStatus(): int
    {
        return 429;
    }

    public function userMessage(): string
    {
        return sprintf(
            'You have used your %d AI requests for today. The limit resets at midnight, and the rest of the marketplace is unaffected.',
            $this->limit
        );
    }
}
