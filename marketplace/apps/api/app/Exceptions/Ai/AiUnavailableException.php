<?php

namespace App\Exceptions\Ai;

use App\Enums\AiRequestStatus;

/** The provider could not be reached, timed out, or refused the call. */
class AiUnavailableException extends AiException
{
    public function __construct(
        string $message = 'AI provider unavailable.',
        private readonly AiRequestStatus $status = AiRequestStatus::Unavailable,
    ) {
        parent::__construct($message);
    }

    public static function timeout(string $provider): self
    {
        return new self(sprintf('Provider [%s] timed out.', $provider), AiRequestStatus::Timeout);
    }

    public static function rateLimited(string $provider): self
    {
        return new self(sprintf('Provider [%s] rate limited the request.', $provider), AiRequestStatus::RateLimited);
    }

    public function requestStatus(): AiRequestStatus
    {
        return $this->status;
    }

    public function userMessage(): string
    {
        return match ($this->status) {
            AiRequestStatus::Timeout => 'The assistant took too long to respond. Please try again — everything else on the marketplace is unaffected.',
            AiRequestStatus::RateLimited => 'The assistant is busy right now. Please try again in a moment.',
            default => 'The assistant is temporarily unavailable. You can continue using the marketplace normally.',
        };
    }
}
