<?php

namespace App\Services\Matching;

/**
 * The composite result for one (requirement, business) pair: the weighted
 * percentage, the per-dimension breakdown that produced it, the ordered
 * "why matched" lines, and a snapshot of the capabilities that made the
 * business relevant.
 *
 * `toReasonPayload()` is what gets persisted into requirement_matches.
 * match_reason, so the explanation the customer and business see is the one
 * the engine actually computed — not something re-derived later against a
 * changed catalogue or re-tuned weights.
 */
final class MatchScore
{
    public function __construct(
        /** Weighted total, 0..100 with two decimals. */
        public readonly float $total,
        /** @var array<string, array{score: float, weight: float, weight_percentage: float, points: float, reason: string|null}> */
        public readonly array $breakdown,
        /** @var string[] Ordered by how much each dimension contributed. */
        public readonly array $reasons,
        /** @var array<int, array{type: string, name: string}> */
        public readonly array $capabilities,
    ) {}

    public function percentage(): int
    {
        return (int) round($this->total);
    }

    public function meetsThreshold(float $minimumScore): bool
    {
        return $this->total >= $minimumScore;
    }

    public function toReasonPayload(): array
    {
        return [
            'reasons' => $this->reasons,
            'breakdown' => $this->breakdown,
            'capabilities' => $this->capabilities,
            'scored_at' => now()->toIso8601String(),
        ];
    }
}
