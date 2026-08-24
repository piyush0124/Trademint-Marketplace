<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use App\Exceptions\Ai\AiUnavailableException;
use App\Services\Ai\Providers\ClaudeProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OllamaProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Resolves the configured provider and answers "is AI usable right now".
 *
 * Deliberately not a Laravel Manager subclass: this needs exactly two behaviours —
 * build a driver from config, and cache the reachability probe — and inheriting a
 * framework manager to get them would obscure both.
 */
class AiProviderManager
{
    /** @var array<string, AiProvider> */
    private array $resolved = [];

    /** @var array<string, class-string<AiProvider>> */
    private const DRIVERS = [
        'ollama' => OllamaProvider::class,
        'openai' => OpenAiProvider::class,
        'gemini' => GeminiProvider::class,
        'claude' => ClaudeProvider::class,
    ];

    /** Register a driver at runtime — the seam used by tests to inject a fake. */
    public function extend(string $name, AiProvider $provider): void
    {
        $this->resolved[$name] = $provider;
    }

    public function provider(?string $name = null): AiProvider
    {
        $name ??= (string) config('ai.provider', 'ollama');

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $config = config("ai.providers.$name");

        if (! is_array($config)) {
            throw new InvalidArgumentException(sprintf('AI provider [%s] is not configured.', $name));
        }

        $driver = (string) ($config['driver'] ?? $name);
        $class = self::DRIVERS[$driver] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException(sprintf('Unknown AI provider driver [%s].', $driver));
        }

        return $this->resolved[$name] = new $class($config);
    }

    /**
     * The model for a task tier, falling back to the provider default.
     *
     * A deployment that sets only AI_MODEL gets that model for everything, which is
     * the sane default. A deployment that cares about cost points `fast` at a small
     * model for classification and extraction, and `strong` at a capable one for
     * conversation — without any calling code knowing tiers exist.
     */
    public function modelFor(string $tier = 'default', ?string $providerName = null): string
    {
        $configured = config("ai.tiers.$tier");

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->provider($providerName)->defaultModel();
    }

    /**
     * Is the provider reachable?
     *
     * Cached for a few seconds because this is called on dashboard renders and status
     * polls. Short TTL so a provider that comes back up is picked up quickly, and a
     * provider that dies is noticed before users file complaints.
     *
     * Never throws — the whole point is to produce a boolean a UI can branch on.
     */
    public function isAvailable(?string $providerName = null): bool
    {
        $name = $providerName ?? (string) config('ai.provider', 'ollama');
        $ttl = (int) config('ai.availability_cache_seconds', 30);

        if ($ttl <= 0) {
            return $this->probeSafely($name);
        }

        return (bool) Cache::remember(
            "ai:available:$name",
            $ttl,
            fn (): bool => $this->probeSafely($name)
        );
    }

    /** Clear the cached probe — used after a config change or by a health command. */
    public function forgetAvailability(?string $providerName = null): void
    {
        Cache::forget('ai:available:'.($providerName ?? (string) config('ai.provider', 'ollama')));
    }

    /**
     * Global kill switch plus per-feature flags.
     *
     * Both are checked before any provider work, so a disabled feature costs nothing
     * and cannot fail in an interesting way.
     */
    public function isEnabled(?string $feature = null): bool
    {
        if (! (bool) config('ai.enabled', false)) {
            return false;
        }

        if ($feature === null) {
            return true;
        }

        return (bool) config("ai.features.$feature", false);
    }

    /**
     * Everything the frontend needs to decide what to render, in one call.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $enabled = (bool) config('ai.enabled', false);
        $name = (string) config('ai.provider', 'ollama');

        return [
            'enabled' => $enabled,
            // Probing a disabled provider would be pointless work and would report
            // a confusing "available but off".
            'available' => $enabled && $this->isAvailable($name),
            'provider' => $name,
            'model' => $this->modelFor('default', $name),
            'features' => collect((array) config('ai.features', []))
                ->map(fn ($value): bool => $enabled && (bool) $value)
                ->all(),
        ];
    }

    /**
     * Assert the provider is usable, or explain why not.
     *
     * @throws AiUnavailableException
     */
    public function ensureAvailable(?string $providerName = null): AiProvider
    {
        $provider = $this->provider($providerName);

        if (! $this->isAvailable($providerName)) {
            throw new AiUnavailableException(sprintf(
                'AI provider [%s] is not reachable.',
                $provider->name()
            ));
        }

        return $provider;
    }

    private function probeSafely(string $name): bool
    {
        try {
            return $this->provider($name)->isAvailable();
        } catch (\Throwable) {
            // A misconfigured provider name must read as unavailable, not blow up a
            // page render.
            return false;
        }
    }
}
