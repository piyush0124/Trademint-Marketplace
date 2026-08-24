<?php

namespace App\Services\Ai\Data;

use App\Exceptions\Ai\AiInvalidResponseException;

/**
 * A normalised provider reply.
 *
 * Every provider's own envelope is flattened to this before it leaves the provider
 * class, so nothing downstream — service, tools, controllers — knows or cares which
 * vendor answered.
 */
final class AiChatResponse
{
    /** @param  array<int, AiToolCall>  $toolCalls */
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls = [],
        public readonly ?string $model = null,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?string $finishReason = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function totalTokens(): ?int
    {
        if ($this->promptTokens === null && $this->completionTokens === null) {
            return null;
        }

        return (int) $this->promptTokens + (int) $this->completionTokens;
    }

    /**
     * Whether the model ran out of room mid-answer.
     *
     * Worth knowing: a truncated reply is not an error, but a truncated *JSON*
     * reply is, and the two need different handling upstream.
     */
    public function wasTruncated(): bool
    {
        return in_array($this->finishReason, ['length', 'max_tokens', 'MAX_TOKENS'], true);
    }

    /**
     * Parse the reply as JSON.
     *
     * Models decorate JSON no matter how firmly they are told not to: markdown
     * fences, a "Here is the JSON:" preamble, trailing commentary. Rather than fail
     * on presentation, this extracts the outermost JSON value and parses that. What
     * it will not do is guess at genuinely broken JSON — a malformed payload raises,
     * so the caller falls back instead of acting on half-understood data.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $text = trim($this->content);

        if ($text === '') {
            throw new AiInvalidResponseException('AI returned an empty response where JSON was expected.');
        }

        // Strip a ```json ... ``` fence if present.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $fenced) === 1) {
            $text = trim($fenced[1]);
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        /*
         * Last resort: take the widest {...} or [...] span in the text.
         *
         * `min()` is given an array rather than spread arguments — spreading a
         * single-element array calls min() with one scalar, which is a TypeError. That
         * is exactly the case a prose reply produces, i.e. the one this branch exists
         * to handle.
         */
        $openings = array_filter(
            [strpos($text, '{'), strpos($text, '[')],
            fn ($position): bool => $position !== false
        );

        $start = $openings === [] ? null : min($openings);
        $end = max(strrpos($text, '}'), strrpos($text, ']'));

        if ($start !== null && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new AiInvalidResponseException(
            $this->wasTruncated()
                ? 'AI response was cut off before the JSON was complete.'
                : 'AI response was not valid JSON.'
        );
    }
}
