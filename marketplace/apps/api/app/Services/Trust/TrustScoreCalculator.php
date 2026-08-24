<?php

namespace App\Services\Trust;

use App\Enums\MatchResponseStatus;
use App\Enums\OrderStatus;
use App\Enums\VerificationStatus;
use App\Models\Business;

/**
 * Phase 9 — the trust score.
 *
 * Three rules this class is built around:
 *
 *  1. **Transparent.** Every factor, its weight, its raw evidence and its
 *     contribution are returned alongside the score. A business can see precisely
 *     why it scored what it did and what would improve it.
 *  2. **Configurable.** Weights and saturation points live in
 *     config('marketplace.trust'), never in the UI and never inline here.
 *  3. **Never invented.** A factor with insufficient evidence is marked
 *     unavailable and *excluded from the weighting* rather than scored as zero or
 *     defaulted to something flattering. A brand new business is therefore
 *     "not enough data yet", not "0% trustworthy" — and the score is computed over
 *     the factors that do have evidence, so it stays honest and comparable.
 *
 * Every factor is derived from real marketplace behaviour: admin verification,
 * profile completeness, completed orders, published reviews, response history and
 * cancellation history. Nothing is self-reported except the profile fields, and
 * those only count towards completeness.
 */
class TrustScoreCalculator
{
    /**
     * @return array{
     *     score: int|null,
     *     factors: array<int, array{key: string, label: string, weight: float, score: float|null, contribution: float|null, available: bool, evidence: string}>,
     *     available_weight: float
     * }
     */
    public function calculate(Business $business): array
    {
        $config = (array) config('marketplace.trust', []);
        $weights = (array) ($config['weights'] ?? []);

        $factors = [
            $this->verification($business, $weights),
            $this->profileCompleteness($business, $config, $weights),
            $this->completedOrders($business, $config, $weights),
            $this->reviewQuality($business, $config, $weights),
            $this->responsiveness($business, $config, $weights),
            $this->reliability($business, $config, $weights),
            $this->accountAge($business, $config, $weights),
        ];

        // Only factors with real evidence take part, and the divisor is their
        // combined weight — so a missing factor neither helps nor hurts.
        $available = array_filter($factors, fn (array $factor) => $factor['available']);
        $availableWeight = array_sum(array_column($available, 'weight'));

        $score = null;
        if ($availableWeight > 0) {
            $earned = array_sum(array_map(fn (array $factor) => $factor['score'] * $factor['weight'], $available));
            $score = (int) round(($earned / $availableWeight) * 100);
        }

        $factors = array_map(function (array $factor) use ($availableWeight): array {
            $factor['contribution'] = $factor['available'] && $availableWeight > 0
                ? round(($factor['score'] * $factor['weight'] / $availableWeight) * 100, 1)
                : null;

            return $factor;
        }, $factors);

        return [
            'score' => $score,
            'factors' => array_values($factors),
            'available_weight' => (float) $availableWeight,
        ];
    }

    /** Admin verification. Always available: "not verified" is itself evidence. */
    private function verification(Business $business, array $weights): array
    {
        $status = $business->verificationStatus();

        $score = match (true) {
            $status->isSuspended() => 0.0,
            $status->isVerified() => 1.0,
            $status === VerificationStatus::Pending => 0.35,
            default => 0.1,
        };

        // Fully checking every identity dimension earns the top of the band.
        if ($status->isVerified() && ! $business->isFullyVerified()) {
            $score = 0.8;
        }

        return $this->factor('verification', 'Verification', $weights, $score, true, $status->label());
    }

    /** How much of the profile a customer can actually read before enquiring. */
    private function profileCompleteness(Business $business, array $config, array $weights): array
    {
        $fields = (array) ($config['profile_fields'] ?? []);

        if ($fields === []) {
            return $this->factor('profile_completeness', 'Profile completeness', $weights, 0.0, false, 'Not configured');
        }

        $filled = collect($fields)->filter(fn (string $field) => ! empty($business->{$field}))->count();
        // Service areas and a catalogue matter as much as the text fields.
        $extras = 0;
        $extrasTotal = 2;
        if ($business->serviceAreas()->exists()) {
            $extras++;
        }
        if ($business->products()->exists() || $business->services()->exists() || $business->listings()->exists()) {
            $extras++;
        }

        $score = ($filled + $extras) / (count($fields) + $extrasTotal);

        return $this->factor('profile_completeness', 'Profile completeness', $weights, min(1.0, $score), true,
            sprintf('%d%% complete', (int) round($score * 100)));
    }

    /** Delivered work, saturating at a configurable volume. */
    private function completedOrders(Business $business, array $config, array $weights): array
    {
        $completed = $business->orders()->where('status', OrderStatus::Completed->value)->count();
        $saturation = max(1, (int) ($config['orders_saturation'] ?? 20));

        if ($completed === 0) {
            return $this->factor('completed_orders', 'Completed orders', $weights, 0.0, false, 'No completed orders yet');
        }

        return $this->factor('completed_orders', 'Completed orders', $weights, min(1.0, $completed / $saturation), true,
            sprintf('%d completed', $completed));
    }

    /** Published review average, shrunk towards neutral until enough reviews exist. */
    private function reviewQuality(Business $business, array $config, array $weights): array
    {
        $reviews = $business->reviews()->publicallyVisible()->get();
        $count = $reviews->count();

        if ($count === 0) {
            return $this->factor('review_quality', 'Customer reviews', $weights, 0.0, false, 'No reviews yet');
        }

        $maxRating = (float) ($config['max_rating'] ?? 5.0);
        $average = $reviews->avg('rating');
        $confidenceAt = max(1, (int) ($config['reviews_confidence'] ?? 5));
        $confidence = min(1.0, $count / $confidenceAt);

        // Shrink towards the mid point until the sample justifies the average.
        $normalised = $maxRating > 0 ? ($average / $maxRating) : 0.0;
        $score = ($normalised * $confidence) + (0.5 * (1 - $confidence));

        return $this->factor('review_quality', 'Customer reviews', $weights, min(1.0, max(0.0, $score)), true,
            sprintf('%.1f from %d review%s', $average, $count, $count === 1 ? '' : 's'));
    }

    /** Does this business actually answer the opportunities it is sent? */
    private function responsiveness(Business $business, array $config, array $weights): array
    {
        $invited = $business->requirementMatches()->whereNotNull('invited_at')->count();
        $minimum = max(1, (int) ($config['responsiveness_min_invitations'] ?? 3));

        if ($invited < $minimum) {
            return $this->factor('responsiveness', 'Response rate', $weights, 0.0, false, 'Not enough history yet');
        }

        $responded = $business->requirementMatches()
            ->where('response_status', '!=', MatchResponseStatus::Pending->value)
            ->count();

        $rate = $responded / $invited;

        return $this->factor('responsiveness', 'Response rate', $weights, min(1.0, $rate), true,
            sprintf('%d%% of %d opportunities answered', (int) round($rate * 100), $invited));
    }

    /** Cancellation and dispute behaviour, once there is enough of a record. */
    private function reliability(Business $business, array $config, array $weights): array
    {
        $total = $business->orders()->count();
        $minimum = max(1, (int) ($config['reliability_min_orders'] ?? 3));

        if ($total < $minimum) {
            return $this->factor('reliability', 'Reliability', $weights, 0.0, false, 'Not enough orders yet');
        }

        $bad = $business->orders()
            ->whereIn('status', [OrderStatus::Cancelled->value, OrderStatus::Disputed->value])
            ->count();

        $score = 1.0 - ($bad / $total);

        return $this->factor('reliability', 'Reliability', $weights, max(0.0, min(1.0, $score)), true,
            $bad === 0 ? 'No cancellations or disputes' : sprintf('%d of %d orders cancelled or disputed', $bad, $total));
    }

    /** Time on the platform — weak, but real. */
    private function accountAge(Business $business, array $config, array $weights): array
    {
        if ($business->created_at === null) {
            return $this->factor('account_age', 'Time on platform', $weights, 0.0, false, 'Unknown');
        }

        $days = $business->created_at->diffInDays(now());
        $saturation = max(1, (int) ($config['account_age_saturation_days'] ?? 730));

        return $this->factor('account_age', 'Time on platform', $weights, min(1.0, $days / $saturation), true,
            sprintf('%d day%s', (int) $days, (int) $days === 1 ? '' : 's'));
    }

    private function factor(string $key, string $label, array $weights, float $score, bool $available, string $evidence): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'weight' => (float) ($weights[$key] ?? 0),
            'score' => round($score, 4),
            'contribution' => null,
            'available' => $available,
            'evidence' => $evidence,
        ];
    }
}
