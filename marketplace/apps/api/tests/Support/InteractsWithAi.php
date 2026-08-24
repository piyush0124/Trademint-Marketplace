<?php

namespace Tests\Support;

use App\Services\Ai\AiProviderManager;

/**
 * Test helper for the AI layer.
 *
 * Swaps in the scripted provider and turns the availability cache off, so a test that
 * marks the provider unavailable is not served a stale "available" from a previous
 * assertion in the same test.
 */
trait InteractsWithAi
{
    protected FakeAiProvider $ai;

    protected function fakeAi(bool $enabled = true): FakeAiProvider
    {
        config([
            'ai.enabled' => $enabled,
            'ai.provider' => 'fake',
            // Probing must be live in tests: caching it would hide the transition
            // between available and unavailable inside a single test.
            'ai.availability_cache_seconds' => 0,
            // Generous so a burst limiter does not intrude on unrelated assertions.
            // The rate-limit test sets its own value.
            'ai.limits.rate_limit' => 1000,
            'ai.providers.fake' => [
                'driver' => 'fake',
                'model' => 'fake-model',
                'embedding_model' => 'fake-embed',
                'cost_per_1k_prompt_tokens' => 0.01,
                'cost_per_1k_completion_tokens' => 0.03,
            ],
        ]);

        $this->ai = new FakeAiProvider;

        // Registered on the singleton so the container, controllers and services all
        // resolve the same instance the test holds a reference to.
        $manager = $this->app->make(AiProviderManager::class);
        $manager->extend('fake', $this->ai);

        return $this->ai;
    }

    /** Turn one AI feature flag off, leaving the rest alone. */
    protected function disableAiFeature(string $feature): void
    {
        config(["ai.features.$feature" => false]);
    }
}
