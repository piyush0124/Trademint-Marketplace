<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\Ai\AiUnavailableException;
use App\Services\Ai\Data\AiChatRequest;
use App\Services\Ai\Data\AiChatResponse;
use App\Services\Ai\Data\AiToolCall;
use Illuminate\Http\Client\PendingRequest;

/**
 * OpenAI-compatible chat completions.
 *
 * Written against the `/v1/chat/completions` shape rather than OpenAI specifically,
 * so it also drives Groq, Together, OpenRouter, vLLM and Azure-style gateways by
 * changing AI_BASE_URL alone. That is worth more than a vendor SDK: the marketplace
 * gets provider choice without another dependency to keep current.
 */
class OpenAiProvider extends AbstractHttpProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        $payload = array_filter([
            'model' => $request->model ?? $this->defaultModel(),
            'messages' => $request->messages,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
        ], fn ($value) => $value !== null);

        if ($request->expectsJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if ($request->tools !== []) {
            $payload['tools'] = array_map(
                fn (array $tool): array => ['type' => 'function', 'function' => $tool],
                $request->tools
            );
            // `auto` not `required`: most turns are conversational and forcing a
            // tool call would make the assistant invent a lookup to satisfy the
            // constraint.
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->send(fn () => $this->authorised($request->timeout)->post('/v1/chat/completions', $payload));

        $body = $response->json();
        $choice = is_array($body['choices'][0] ?? null) ? $body['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $rawCall) {
            $call = AiToolCall::fromProviderPayload($rawCall);

            if ($call !== null) {
                $toolCalls[] = $call;
            }
        }

        return new AiChatResponse(
            content: (string) ($message['content'] ?? ''),
            toolCalls: $toolCalls,
            model: $body['model'] ?? $payload['model'],
            promptTokens: isset($body['usage']['prompt_tokens']) ? (int) $body['usage']['prompt_tokens'] : null,
            completionTokens: isset($body['usage']['completion_tokens']) ? (int) $body['usage']['completion_tokens'] : null,
            finishReason: $choice['finish_reason'] ?? null,
        );
    }

    public function embed(array $texts, ?string $model = null): array
    {
        if (! $this->supportsEmbeddings()) {
            throw new AiUnavailableException('No embedding model is configured for OpenAI.');
        }

        // Batched: this endpoint takes an array, so one call covers every input.
        $response = $this->send(fn () => $this->authorised()->post('/v1/embeddings', [
            'model' => $model ?? (string) $this->config['embedding_model'],
            'input' => array_values($texts),
        ]));

        return collect((array) $response->json('data', []))
            // The API documents order but sorts defensively: a reordered batch would
            // silently attach the wrong vector to the wrong record.
            ->sortBy('index')
            ->map(fn (array $row): array => array_map('floatval', (array) ($row['embedding'] ?? [])))
            ->values()
            ->all();
    }

    protected function probe(): bool
    {
        // No key means no possibility of a call succeeding, so report unavailable
        // without touching the network.
        if ($this->apiKey() === null) {
            return false;
        }

        return $this->authorised(8)->timeout(8)->get('/v1/models')->successful();
    }

    private function authorised(?int $timeout = null): PendingRequest
    {
        $client = $this->client($timeout);
        $key = $this->apiKey();

        // withToken rather than a hand-built header, so the value is held by the
        // client and never interpolated into a string we might later log.
        return $key === null ? $client : $client->withToken($key);
    }
}
