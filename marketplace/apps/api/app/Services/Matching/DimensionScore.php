<?php

namespace App\Services\Matching;

/**
 * One scoring dimension's result: a normalised 0..1 score plus the plain
 * English reason behind it. Every dimension returns a reason, including a
 * zero score — "why not matched well" is as useful to show as "why matched".
 */
final class DimensionScore
{
    public function __construct(
        public readonly float $score,
        public readonly ?string $reason = null,
    ) {}

    public static function of(float $score, ?string $reason = null): self
    {
        // Sub-scores are contractually 0..1; clamping here means a
        // mis-configured weight or bonus can never inflate a match above
        // 100% or drag it below zero.
        return new self(max(0.0, min(1.0, $score)), $reason);
    }
}
