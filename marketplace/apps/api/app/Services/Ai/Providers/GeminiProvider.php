<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\Ai\AiUnavailableException;
use App\Services\Ai\Data\AiChatRequest;
use App\Services\Ai\Data\AiChatResponse;
use App\Services\Ai\Data\AiToolCall;
use Illuminate\Http\Client\PendingRequest;

/**
 * Google Gemini generateContent.
 *
 * The most divergent of the four: messages are `contents` with `parts`, the
 * assistant role is called `model`, the system prompt is `systemInstruction`,
 * generation settings live under `generationConfig`, and tools are wrapped in a
 * `functionDeclarations` array. All of that is contained here.
 */
class GeminiProvider extends AbstractHttpProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        $model = $request->model ?? $this->defaultModel();

        $system = collect($request->messages)
            ->where('role', 'system')
            ->pluck('content')
            ->implode("\n\n");

        $contents = collect($request->messages)
            ->reject(fn (array $message): bool => $message['role'] === 'system')
            ->map(fn (array $message): array => [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $message['content']]],
            ])
            ->values()
            ->all();

        $payload = [
            'contents' => $contents,
            'generationConfig' => array_filter([
                'temperature' => $request->temperature,
                'maxOutputTokens' => $request->maxTokens,
                'responseMimeType' => $request->expectsJson ? 'application/json' : null,
            ], fn ($value) => $value !== null),
        ];

        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        if ($request->tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                ], $request->tools),
            ]];
        }

        $response = $this->send(
            fn () => $this->authorised($request->timeout)
                ->post(sprintf('/v1beta/models/%s:generateContent', $model), $payload)
        );

        $body = $response->json();
        $candidate = is_array($body['candidates'][0] ?? null) ? $body['candidates'][0] : [];

        $text = '';
        $toolCalls = [];

        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }

            if (is_array($part['functionCall'] ?? null)) {
                // Gemini names the arguments bag `args`; AiToolCall already accepts
                // that alias.
                $call = AiToolCall::fromProviderPayload($part['functionCall']);

                if ($call !== null) {
                    $toolCalls[] = $call;
                }
            }
        }

        return new AiChatResponse(
            content: $text,
            toolCalls: $toolCalls,
            model: $model,
            promptTokens: isset($body['usageMetadata']['promptTokenCount']) ? (int) $body['usageMetadata']['promptTokenCount'] : null,
            completionTokens: isset($body['usageMetadata']['candidatesTokenCount']) ? (int) $body['usageMetadata']['candidatesTokenCount'] : null,
            finishReason: $candidate['finishReason'] ?? null,
        );
    }

    public function embed(array $texts, ?string $model = null): array
    {
        if (! $this->supportsEmbeddings()) {
            throw new AiUnavailableException('No embedding model is configured for Gemini.');
        }

        $model ??= (string) $this->config['embedding_model'];

        $response = $this->send(
            fn () => $this->authorised()->post(sprintf('/v1beta/models/%s:batchEmbedContents', $model), [
                'requests' => array_map(fn (string $text): array => [
                    'model' => 'models/'.$model,
                    'content' => ['parts' => [['text' => $text]]],
                ], array_values($texts)),
            ])
        );

        return collect((array) $response->json('embeddings', []))
            ->map(fn (array $row): array => array_map('floatval', (array) ($row['values'] ?? [])))
            ->values()
            ->all();
    }

    protected function probe(): bool
    {
        if ($this->apiKey() === null) {
            return false;
        }

        return $this->authorised(8)->timeout(8)
            ->get(sprintf('/v1beta/models/%s', $this->defaultModel()))
            ->successful();
    }

    /**
     * Gemini authenticates with a header rather than a query parameter.
     *
     * `?key=` also works, but a key in a URL ends up in access logs, proxy logs and
     * error reports. The header keeps it out of all three.
     */
    private function authorised(?int $timeout = null): PendingRequest
    {
        $client = $this->client($timeout);
        $key = $this->apiKey();

        return $key === null ? $client : $client->withHeaders(['x-goog-api-key' => $key]);
    }
}
