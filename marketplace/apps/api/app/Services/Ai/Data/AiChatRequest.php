<?php

namespace App\Services\Ai\Data;

/**
 * A provider-agnostic chat call.
 *
 * Deliberately the lowest common denominator across Ollama, OpenAI, Gemini and
 * Anthropic. Anything one provider supports and another does not is expressed as a
 * hint the provider may honour or ignore, never as a hard requirement — otherwise
 * swapping AI_PROVIDER would change behaviour, which is the coupling this whole
 * abstraction exists to prevent.
 */
final class AiChatRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<int, array<string, mixed>>  $tools  JSON-schema tool definitions.
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $model = null,
        public readonly float $temperature = 0.2,
        public readonly ?int $maxTokens = null,
        /**
         * Ask for strict JSON. Every provider here supports some form of it; where
         * it is only advisory the caller must still validate, which AiService does
         * regardless.
         */
        public readonly bool $expectsJson = false,
        public readonly array $tools = [],
        public readonly ?int $timeout = null,
    ) {}

    /** @param  array<int, array<string, mixed>>  $tools */
    public function withTools(array $tools): self
    {
        return new self(
            $this->messages,
            $this->model,
            $this->temperature,
            $this->maxTokens,
            $this->expectsJson,
            $tools,
            $this->timeout,
        );
    }

    /** @param  array<int, array{role: string, content: string}>  $messages */
    public function withMessages(array $messages): self
    {
        return new self(
            $messages,
            $this->model,
            $this->temperature,
            $this->maxTokens,
            $this->expectsJson,
            $this->tools,
            $this->timeout,
        );
    }

    /**
     * Rough token estimate, for providers that report no usage at all.
     *
     * Four characters per token is the usual English approximation. It is an
     * estimate and is stored as one — the point is that a provider without usage
     * reporting still produces a non-null cost figure rather than silently looking
     * free. Indic scripts tokenise less efficiently, so this under-counts Hindi;
     * that is acknowledged rather than corrected with a fake multiplier.
     */
    public function estimatedPromptTokens(): int
    {
        $characters = array_sum(array_map(
            fn (array $message): int => mb_strlen((string) ($message['content'] ?? '')),
            $this->messages
        ));

        return (int) ceil($characters / 4);
    }
}
