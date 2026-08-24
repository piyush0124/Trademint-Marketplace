<?php

namespace Tests\Support;

use App\Contracts\AiProvider;
use App\Exceptions\Ai\AiUnavailableException;
use App\Services\Ai\Data\AiChatRequest;
use App\Services\Ai\Data\AiChatResponse;
use App\Services\Ai\Data\AiToolCall;

/**
 * A scripted provider for tests.
 *
 * Tests must never depend on a running Ollama or a paid API: that would make the suite
 * slow, non-deterministic and unrunnable in CI. This implements the same interface, so
 * everything above it — AiService, the tool loop, quota, logging, confirmation — is
 * exercised for real. Only the network call is replaced.
 *
 * It also records what it was asked, which is how the tests assert on prompt content:
 * that untrusted input arrives fenced, that no API key is ever in a prompt, and that
 * out-of-role tools are not even offered to the model.
 */
class FakeAiProvider implements AiProvider
{
    /** @var array<int, AiChatResponse> Responses handed out in order. */
    private array $queue = [];

    /** @var array<int, AiChatRequest> Every request received, for assertions. */
    public array $received = [];

    public bool $available = true;

    /** When set, `chat()` throws this instead of answering. */
    public ?\Throwable $failWith = null;

    public int $callCount = 0;

    public function __construct(public string $model = 'fake-model') {}

    /* ---------------------------------------------------------------- Setup */

    /** Queue a plain text reply. */
    public function willReply(string $content, ?int $promptTokens = 100, ?int $completionTokens = 40): self
    {
        $this->queue[] = new AiChatResponse(
            content: $content,
            model: $this->model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            finishReason: 'stop',
        );

        return $this;
    }

    /**
     * Queue a tool call.
     *
     * Note the deliberate ability to name a tool the caller was never offered — that
     * is exactly the case the authorization tests need to exercise.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function willCallTool(string $tool, array $arguments = [], string $content = ''): self
    {
        $this->queue[] = new AiChatResponse(
            content: $content,
            toolCalls: [new AiToolCall($tool, $arguments, 'call_'.count($this->queue))],
            model: $this->model,
            promptTokens: 120,
            completionTokens: 20,
            finishReason: 'tool_calls',
        );

        return $this;
    }

    /** Queue a JSON reply, optionally malformed to test the fallback path. */
    public function willReplyJson(array|string $payload): self
    {
        return $this->willReply(is_string($payload) ? $payload : (string) json_encode($payload));
    }

    public function willFail(\Throwable $exception): self
    {
        $this->failWith = $exception;

        return $this;
    }

    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    /* ------------------------------------------------------------ Assertions */

    public function lastRequest(): ?AiChatRequest
    {
        return $this->received === [] ? null : $this->received[count($this->received) - 1];
    }

    /** Every message content across every request, flattened. */
    public function allPromptText(): string
    {
        return collect($this->received)
            ->flatMap(fn (AiChatRequest $request): array => $request->messages)
            ->pluck('content')
            ->implode("\n");
    }

    /** @return array<int, string> Tool names offered on the first request. */
    public function offeredToolNames(): array
    {
        $request = $this->received[0] ?? null;

        return $request === null ? [] : array_column($request->tools, 'name');
    }

    /* -------------------------------------------------------------- Provider */

    public function name(): string
    {
        return 'fake';
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        $this->received[] = $request;
        $this->callCount++;

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        // Falling back to a generic reply rather than throwing keeps a test that
        // forgot to queue enough responses readable — it fails on its assertion, not
        // on an array offset.
        return array_shift($this->queue) ?? new AiChatResponse(
            content: 'Fake reply.',
            model: $this->model,
            promptTokens: 50,
            completionTokens: 10,
            finishReason: 'stop',
        );
    }

    public function embed(array $texts, ?string $model = null): array
    {
        if (! $this->available) {
            throw new AiUnavailableException('Fake provider is unavailable.');
        }

        // Deterministic pseudo-vectors: same input always gives the same vector, so a
        // similarity assertion is stable.
        return array_map(
            fn (string $text): array => array_map(
                fn (int $i): float => round(sin(crc32($text) + $i), 6),
                range(0, 7)
            ),
            array_values($texts)
        );
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function defaultModel(): string
    {
        return $this->model;
    }
}
