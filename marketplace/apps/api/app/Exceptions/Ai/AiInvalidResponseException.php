<?php

namespace App\Exceptions\Ai;

use App\Enums\AiRequestStatus;

/**
 * The provider answered, but not with anything usable — malformed JSON, a missing
 * field, a schema violation, or a tool call naming a tool that does not exist.
 *
 * Separated from unavailable because the remedy differs: this is a prompt or schema
 * problem, or a model too weak for the task, not an outage.
 */
class AiInvalidResponseException extends AiException
{
    public function __construct(string $message = 'AI returned an unusable response.')
    {
        parent::__construct($message);
    }

    public function requestStatus(): AiRequestStatus
    {
        return AiRequestStatus::InvalidResponse;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function userMessage(): string
    {
        return 'The assistant could not produce a usable answer. Please rephrase, or use the standard form instead.';
    }
}
