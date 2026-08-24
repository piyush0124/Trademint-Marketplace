<?php

namespace App\Services\Ai\Data;

/**
 * A tool the model asked to run.
 *
 * Carries the model's *request*, nothing more. The name is not trusted (it is
 * resolved against the registry), the arguments are not trusted (they are validated
 * against the tool's schema), and the caller is not taken from here at all (it comes
 * from the authenticated session). A tool call is a suggestion, not an instruction.
 */
final class AiToolCall
{
    /** @param  array<string, mixed>  $arguments */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments = [],
        /** Provider-assigned id, echoed back so the model can pair result to call. */
        public readonly ?string $id = null,
    ) {}

    /**
     * Build from a provider payload, tolerating the shapes they actually send.
     *
     * Arguments arrive as a JSON string from OpenAI-compatible APIs and as an object
     * from Ollama and Gemini. A model will also occasionally send `"{}"`, `null`, or
     * a JSON string containing another JSON string — all of which resolve to "no
     * arguments" rather than an exception, because a malformed tool call should
     * degrade to a clarifying question, not a 500.
     */
    public static function fromProviderPayload(mixed $payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        // OpenAI/Ollama nest the call under `function`; Gemini and Claude are flat.
        $function = is_array($payload['function'] ?? null) ? $payload['function'] : $payload;

        $name = $function['name'] ?? $payload['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        $rawArguments = $function['arguments'] ?? $payload['arguments'] ?? $payload['args'] ?? $payload['input'] ?? [];

        if (is_string($rawArguments)) {
            $decoded = json_decode($rawArguments, true);
            $rawArguments = is_array($decoded) ? $decoded : [];
        }

        return new self(
            $name,
            is_array($rawArguments) ? $rawArguments : [],
            isset($payload['id']) && is_scalar($payload['id']) ? (string) $payload['id'] : null,
        );
    }
}
