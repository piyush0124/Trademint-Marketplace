<?php

namespace App\Services\Ai;

use App\Enums\AiRequestStatus;
use App\Exceptions\Ai\AiQuotaExceededException;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\Ai\Data\AiChatResponse;

/**
 * Quota enforcement and the cost ledger.
 *
 * Counted from the `ai_requests` table rather than the cache, on purpose. A
 * cache-backed quota resets when the cache is flushed or the container restarts, and
 * "redeploy to get more AI" is not a limit. The database costs one indexed count per
 * request and cannot be reset by accident.
 *
 * Failed provider calls still count. A user whose calls all fail would otherwise be
 * free to retry without bound, which is the worst possible load to add to a provider
 * that is already struggling.
 */
class AiUsageTracker
{
    /**
     * @throws AiQuotaExceededException
     */
    public function assertWithinQuota(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $limit = $this->dailyLimitFor($user);

        // Zero or negative is read as "unlimited" so an operator can disable the
        // quota without editing code.
        if ($limit <= 0) {
            return;
        }

        if ($this->usedToday($user) >= $limit) {
            throw new AiQuotaExceededException($limit);
        }
    }

    public function dailyLimitFor(User $user): int
    {
        return (int) config('ai.limits.requests_per_day.'.$user->role->value, 0);
    }

    public function usedToday(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return AiRequest::query()->forUser($user->id)->today()->billable()->count();
    }

    public function remainingToday(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        $limit = $this->dailyLimitFor($user);

        // Null means unlimited, which the API reports as such instead of a
        // misleading large number.
        return $limit <= 0 ? null : max(0, $limit - $this->usedToday($user));
    }

    /**
     * Write the ledger row for one provider call.
     *
     * Note the absence of prompt or completion text. This table answers "what did we
     * spend and was it slow"; the conversation content lives in `ai_messages`.
     * Keeping them apart means a cost report can be shared with someone who has no
     * business reading customers' requirements.
     */
    public function record(
        ?User $user,
        string $feature,
        string $provider,
        string $model,
        AiRequestStatus $status,
        ?int $latencyMs = null,
        ?AiChatResponse $response = null,
        ?int $estimatedPromptTokens = null,
        ?string $errorCode = null,
    ): AiRequest {
        $promptTokens = $response?->promptTokens ?? $estimatedPromptTokens;
        $completionTokens = $response?->completionTokens;

        $total = $promptTokens === null && $completionTokens === null
            ? null
            : (int) $promptTokens + (int) $completionTokens;

        return AiRequest::create([
            'user_id' => $user?->id,
            'actor_role' => $user?->role?->value,
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $total,
            'estimated_cost' => $this->estimateCost($provider, $promptTokens, $completionTokens),
            'error_code' => $errorCode,
        ]);
    }

    /**
     * Estimated spend for one call, from configured per-1K rates.
     *
     * Rates default to zero and are meant to be set from the deployment's own
     * billing page. No commercial pricing is hardcoded here: vendor prices change
     * often, and a stale number in config that looks authoritative is worse than an
     * honest zero. Local Ollama genuinely is zero, which is why that is a sensible
     * default rather than a placeholder.
     */
    private function estimateCost(string $provider, ?int $promptTokens, ?int $completionTokens): ?float
    {
        if ($promptTokens === null && $completionTokens === null) {
            return null;
        }

        $promptRate = (float) config("ai.providers.$provider.cost_per_1k_prompt_tokens", 0);
        $completionRate = (float) config("ai.providers.$provider.cost_per_1k_completion_tokens", 0);

        return round(
            ((int) $promptTokens / 1000) * $promptRate + ((int) $completionTokens / 1000) * $completionRate,
            6
        );
    }
}
