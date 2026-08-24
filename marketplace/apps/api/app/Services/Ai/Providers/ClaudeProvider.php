<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\Ai\AiUnavailableException;
use App\Services\Ai\Data\AiChatRequest;
use App\Services\Ai\Data\AiChatResponse;
use App\Services\Ai\Data\AiToolCall;
use Illuminate\Http\Client\PendingRequest;

/**
 * Anthropic Messages API.
 *
 * Two shape differences worth naming, both handled here so callers stay unaware:
 * the system prompt is a top-level field rather than a message with role `system`,
 * and the reply is a list of content blocks (text and tool_use interleaved) rather
 * than a single string.
 *
 * Anthropic publishes no embeddings endpoint, so `embed()` refuses rather than
 * pretending. `supportsEmbeddings()` returns false because the config sets the
 * embedding model to null.
 */
class ClaudeProvider extends AbstractHttpProvider
{
    public function name(): string
    {
        return 'claude';
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        // Anthropic takes the system prompt out of band. Multiple system messages
        // are joined rather than dropped — losing one would silently discard a
        // safety instruction.
        $system = collect($request->messages)
            ->where('role', 'system')
            ->pluck('content')
            ->implode("\n\n");

        $messages = collect($request->messages)
            ->reject(fn (array $message): bool => $message['role'] === 'system')
            ->map(fn (array $message): array => [
                // Anthropic knows only user and assistant; tool output is relayed as
                // a user turn, which is how their own tool examples do it.
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $message['content'],
            ])
            ->values()
            ->all();

        $payload = array_filter([
            'model' => $request->model ?? $this->defaultModel(),
            'system' => $system !== '' ? $system : null,
            'messages' => $messages,
            'temperature' => $request->temperature,
            // Required by this API, unlike the others where it is optional.
            'max_tokens' => $request->maxTokens ?? (int) config('ai.generation.max_tokens', 1200),
        ], fn ($value) => $value !== null);

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                // Anthropic calls the schema `input_schema`.
                'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
            ], $request->tools);
        }

        $response = $this->send(fn () => $this->authorised($request->timeout)->post('/v1/messages', $payload));

        $body = $response->json();

        $text = '';
        $toolCalls = [];

        foreach ((array) ($body['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');

                continue;
            }

            if (($block['type'] ?? null) === 'tool_use') {
                $call = AiToolCall::fromProviderPayload($block);

                if ($call !== null) {
                    $toolCalls[] = $call;
                }
            }
        }

        return new AiChatResponse(
            content: $text,
            toolCalls: $toolCalls,
            model: $body['model'] ?? $payload['model'],
            promptTokens: isset($body['usage']['input_tokens']) ? (int) $body['usage']['input_tokens'] : null,
            completionTokens: isset($body['usage']['output_tokens']) ? (int) $body['usage']['output_tokens'] : null,
            finishReason: $body['stop_reason'] ?? null,
        );
    }

    public function embed(array $texts, ?string $model = null): array
    {
        throw new AiUnavailableException(
            'Anthropic does not provide an embeddings API. Configure a different provider for embeddings.'
        );
    }

    public function supportsEmbeddings(): bool
    {
        return false;
    }

    protected function probe(): bool
    {
        if ($this->apiKey() === null) {
            return false;
        }

        // No unauthenticated health endpoint, so the cheapest proof of life is a
        // one-token generation. Capped at 1 token so the probe costs almost nothing.
        return $this->authorised(8)->timeout(8)->post('/v1/messages', [
            'model' => $this->defaultModel(),
            'max_tokens' => 1,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ])->successful();
    }

    private function authorised(?int $timeout = null): PendingRequest
    {
        $client = $this->client($timeout)->withHeaders([
            'anthropic-version' => (string) ($this->config['anthropic_version'] ?? '2023-06-01'),
        ]);

        $key = $this->apiKey();

        return $key === null ? $client : $client->withHeaders(['x-api-key' => $key]);
    }
}
