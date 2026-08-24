<?php

namespace App\Services\Ai\Providers;

use App\Contracts\AiProvider;
use App\Exceptions\Ai\AiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared plumbing for the HTTP-based providers.
 *
 * Its real job is turning four vendors' failure modes into one vocabulary, and doing
 * it without leaking credentials. A provider error body routinely contains request
 * ids, internal hostnames and — with some gateways — a prefix of the API key. None
 * of that may reach a user, an exception message or a log line, so responses are
 * classified by status code and the body is never echoed verbatim.
 */
abstract class AbstractHttpProvider implements AiProvider
{
    /** @param  array<string, mixed>  $config */
    public function __construct(protected readonly array $config) {}

    public function defaultModel(): string
    {
        return (string) ($this->config['model'] ?? '');
    }

    public function supportsEmbeddings(): bool
    {
        return ! empty($this->config['embedding_model']);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    protected function timeout(?int $override = null): int
    {
        return $override ?? (int) ($this->config['timeout'] ?? 60);
    }

    protected function apiKey(): ?string
    {
        $key = $this->config['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * A configured client.
     *
     * The retry predicate is the important part. A bare `retry(2, 300)` also retries a
     * *read* timeout, which is wrong twice over: it doubles the wait a user sits through,
     * and on a metered provider it pays for a generation that may well have completed
     * server-side. Live testing against Ollama showed exactly this — a 120s cold-start
     * timeout took 415s to surface because the request was silently retried.
     *
     * So only a genuine connection failure is retried, where nothing was generated and
     * nothing was billed. A slow generation is reported as a timeout the first time.
     */
    protected function client(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout($this->timeout($timeout))
            ->connectTimeout(min(10, $this->timeout($timeout)))
            ->retry(2, 300, function (Throwable $exception): bool {
                if (! $exception instanceof ConnectionException) {
                    return false;
                }

                // "Connection refused" is worth one retry — a daemon can be a moment from
                // accepting. "Operation timed out" is not: the model is simply slow, and
                // asking again makes the user wait twice as long for the same answer.
                return ! $this->looksLikeTimeout($exception->getMessage());
            }, throw: false)
            ->acceptJson()
            ->asJson();
    }

    protected function looksLikeTimeout(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'timed out') || str_contains($lower, 'timeout');
    }

    /**
     * Execute a request and normalise every failure.
     *
     * @param  callable(): Response  $call
     *
     * @throws AiUnavailableException
     */
    protected function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $exception) {
            // Distinguishes "nothing listening" from "listening but slow", because
            // the operator fix differs: start the service vs. raise the timeout.
            throw $this->looksLikeTimeout($exception->getMessage())
                ? AiUnavailableException::timeout($this->name())
                : new AiUnavailableException(sprintf('Could not reach AI provider [%s].', $this->name()));
        } catch (Throwable $exception) {
            $this->logFailure('transport', $exception->getMessage());

            throw new AiUnavailableException(sprintf('AI provider [%s] request failed.', $this->name()));
        }

        if ($response->successful()) {
            return $response;
        }

        return $this->throwForStatus($response);
    }

    /**
     * Map an HTTP status onto our own failure vocabulary.
     *
     * Note that the response body is logged but never placed in the exception
     * message — the exception text is user-visible via the API layer.
     */
    protected function throwForStatus(Response $response): never
    {
        $status = $response->status();

        $this->logFailure((string) $status, $response->body());

        throw match (true) {
            $status === 401 || $status === 403 => new AiUnavailableException(
                sprintf('AI provider [%s] rejected the credentials.', $this->name())
            ),
            $status === 404 => new AiUnavailableException(
                sprintf('AI provider [%s] does not have the configured model.', $this->name())
            ),
            $status === 429 => AiUnavailableException::rateLimited($this->name()),
            $status === 408 || $status === 504 => AiUnavailableException::timeout($this->name()),
            default => new AiUnavailableException(
                sprintf('AI provider [%s] returned an error (HTTP %d).', $this->name(), $status)
            ),
        };
    }

    /**
     * Log a provider failure with the body redacted.
     *
     * Anything that looks like a bearer token or key is masked before it is written,
     * because provider errors do sometimes echo the credential they rejected and
     * production logs are not a safe place for that. Truncated too — a 40KB HTML
     * error page from a proxy tells you nothing the status code did not.
     */
    protected function logFailure(string $reason, string $body): void
    {
        Log::warning('AI provider call failed', [
            'provider' => $this->name(),
            'model' => $this->defaultModel(),
            'reason' => $reason,
            'body' => Str::limit($this->redact($body), 500),
        ]);
    }

    protected function redact(string $text): string
    {
        return (string) preg_replace(
            [
                '/\b(sk|xai|gsk|api|key|token)[-_][A-Za-z0-9\-_]{8,}/i',
                '/\bBearer\s+[A-Za-z0-9\-._~+\/]{8,}=*/i',
                '/"(api[_-]?key|authorization|token|secret)"\s*:\s*"[^"]*"/i',
            ],
            ['[redacted]', 'Bearer [redacted]', '"$1":"[redacted]"'],
            $text
        );
    }

    /**
     * Reachability probe.
     *
     * Never throws, by contract — callers use this to decide whether to render a
     * button. A short fixed timeout regardless of the generation timeout, since a
     * health check that takes two minutes is not a health check.
     */
    public function isAvailable(): bool
    {
        if ($this->baseUrl() === '') {
            return false;
        }

        try {
            return $this->probe();
        } catch (Throwable) {
            return false;
        }
    }

    /** Provider-specific liveness check. Must not throw for an ordinary outage. */
    abstract protected function probe(): bool;
}
