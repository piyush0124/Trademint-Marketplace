<?php

namespace App\Exceptions\Ai;

use App\Enums\AiRequestStatus;

/**
 * AI is switched off, either globally or for this specific feature.
 *
 * Distinct from unavailable on purpose: "we turned this off" and "the provider is
 * down" need different messages to the user and different reactions from an
 * operator.
 */
class AiDisabledException extends AiException
{
    public function __construct(private readonly ?string $feature = null)
    {
        parent::__construct($feature === null
            ? 'AI is disabled.'
            : sprintf('AI feature [%s] is disabled.', $feature));
    }

    public function requestStatus(): AiRequestStatus
    {
        return AiRequestStatus::Disabled;
    }

    public function userMessage(): string
    {
        return 'AI assistance is turned off on this deployment. All marketplace features remain available.';
    }
}
