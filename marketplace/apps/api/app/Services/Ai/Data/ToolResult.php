<?php

namespace App\Services\Ai\Data;

/**
 * The outcome of a tool invocation.
 *
 * Failure is a value here rather than an exception, because a tool being refused is
 * a normal conversational event: the model should be told "you are not allowed to do
 * that" and say something sensible, not have the whole request 500.
 */
final class ToolResult
{
    /** @param  array<string, mixed>  $data */
    private function __construct(
        public readonly string $tool,
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?string $error = null,
        /** Set when a write tool was parked for confirmation instead of executed. */
        public readonly ?string $pendingActionUuid = null,
        public readonly ?string $confirmationSummary = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function success(string $tool, array $data): self
    {
        return new self($tool, true, $data);
    }

    public static function failure(string $tool, string $error): self
    {
        return new self($tool, false, [], $error);
    }

    /** A write tool that now needs the user to say yes. */
    public static function awaitingConfirmation(string $tool, string $actionUuid, string $summary): self
    {
        return new self($tool, true, [
            'status' => 'awaiting_confirmation',
            'summary' => $summary,
        ], null, $actionUuid, $summary);
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->pendingActionUuid !== null;
    }

    /**
     * Rendered for the model.
     *
     * JSON rather than prose so the model treats it as data to report, not text to
     * paraphrase. The `ok` flag is explicit: a model shown a bare error string tends
     * to invent a plausible answer instead of relaying the refusal.
     */
    public function forModel(): string
    {
        return (string) json_encode(
            $this->ok
                ? ['tool' => $this->tool, 'ok' => true, 'result' => $this->data]
                : ['tool' => $this->tool, 'ok' => false, 'error' => $this->error],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
