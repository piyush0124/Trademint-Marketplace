<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\Ai\AiUnavailableException;
use App\Services\Ai\Data\AiChatRequest;
use App\Services\Ai\Data\AiChatResponse;
use App\Services\Ai\Data\AiToolCall;

/**
 * Ollama — the development default.
 *
 * Chosen as the default for two reasons beyond cost. It needs no API key, so a
 * developer clones the repo and the AI features work; and inference happens on the
 * machine, so marketplace data — requirements, quotes, business documents — never
 * leaves it during development. For a B2B marketplace handling other companies'
 * commercial terms, that second point matters more than the first.
 *
 * API: POST /api/chat and POST /api/embeddings. Tool calling is supported by
 * recent Ollama with tool-capable models; models without it simply return prose,
 * which the tool loop treats as "no tool needed" rather than an error.
 */
class OllamaProvider extends AbstractHttpProvider
{
    public function name(): string
    {
        return 'ollama';
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        $payload = [
            'model' => $request->model ?? $this->defaultModel(),
            'messages' => $this->normaliseMessages($request->messages),
            'stream' => false,
            /*
             * Keep the model resident between calls.
             *
             * Ollama evicts a model after 5 minutes idle by default, and reloading an 8B
             * model costs tens of seconds — live testing measured a cold first call at
             * over 120s against ~25s warm. Without this, any user who pauses to read a
             * reply pays the load again on their next question, which reads as the
             * assistant being broken rather than cold.
             *
             * The cost is RAM held while idle, which is the right trade on a dedicated
             * box and configurable for one that is not.
             */
            'keep_alive' => (string) ($this->config['keep_alive'] ?? '30m'),
            'options' => array_filter([
                'temperature' => $request->temperature,
                // Ollama's name for max output tokens.
                'num_predict' => $request->maxTokens,
            ], fn ($value) => $value !== null),
        ];

        if ($request->expectsJson) {
            // Ollama constrains sampling to valid JSON, which is stronger than a
            // prompt instruction — but the caller still validates, because valid
            // JSON is not the same as JSON matching our schema.
            $payload['format'] = 'json';
        }

        if ($request->tools !== []) {
            $payload['tools'] = $this->formatTools($request->tools);
        }

        $response = $this->send(fn () => $this->client($request->timeout)->post('/api/chat', $payload));

        $body = $response->json();
        $message = is_array($body['message'] ?? null) ? $body['message'] : [];

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
            // Ollama reports counts under these names; absent on some builds.
            promptTokens: isset($body['prompt_eval_count']) ? (int) $body['prompt_eval_count'] : null,
            completionTokens: isset($body['eval_count']) ? (int) $body['eval_count'] : null,
            finishReason: $body['done_reason'] ?? null,
        );
    }

    public function embed(array $texts, ?string $model = null): array
    {
        if (! $this->supportsEmbeddings()) {
            throw new AiUnavailableException('No embedding model is configured for Ollama.');
        }

        $model ??= (string) $this->config['embedding_model'];
        $vectors = [];

        // Ollama's embeddings endpoint takes one prompt per call. Sequential is
        // correct here rather than slow-by-accident: local inference is
        // single-GPU/CPU bound, so firing these in parallel would queue anyway.
        foreach ($texts as $text) {
            $response = $this->send(fn () => $this->client()->post('/api/embeddings', [
                'model' => $model,
                'prompt' => $text,
            ]));

            $vectors[] = array_map('floatval', (array) $response->json('embedding', []));
        }

        return $vectors;
    }

    /**
     * Is a local Ollama actually running?
     *
     * `/api/tags` is the cheapest endpoint that proves the daemon is up, and it also
     * lets us confirm the configured model is pulled — "Ollama is running but you
     * never pulled llama3.1" is the most common local setup failure, and reporting
     * it as unavailable here is what turns it into a clear fallback message instead
     * of a 404 mid-conversation.
     */
    protected function probe(): bool
    {
        $response = $this->client(5)->timeout(5)->get('/api/tags');

        if (! $response->successful()) {
            return false;
        }

        $installed = collect((array) $response->json('models', []))
            ->pluck('name')
            ->filter()
            ->map(fn ($name): string => (string) $name)
            ->all();

        if ($installed === []) {
            return false;
        }

        $wanted = $this->defaultModel();

        // Tags carry an explicit tag ("llama3.1:latest") while AI_MODEL is usually
        // written without one, so compare on the base name.
        return collect($installed)->contains(
            fn (string $name): bool => $name === $wanted || str_starts_with($name, $wanted.':')
        );
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function normaliseMessages(array $messages): array
    {
        return array_map(fn (array $message): array => [
            'role' => $message['role'],
            'content' => (string) $message['content'],
        ], $messages);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function formatTools(array $tools): array
    {
        // Ollama follows the OpenAI tool envelope.
        return array_map(fn (array $tool): array => [
            'type' => 'function',
            'function' => $tool,
        ], $tools);
    }
}
