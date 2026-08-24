<?php

namespace App\Rules;

use App\Support\Spam\SuspiciousContent;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule for user-written free text.
 *
 * Wraps the SuspiciousContent heuristics so a Form Request can apply them with
 * `new CleanText` instead of each controller re-implementing the checks — which
 * is how the same field ends up validated three different ways in three places.
 *
 * ---------------------------------------------------------------------------
 * BACKEND IS THE SOURCE OF TRUTH
 * ---------------------------------------------------------------------------
 * The frontend runs comparable checks to give immediate feedback, and that is a
 * convenience only. This rule runs regardless of what the client did, because
 * anything enforced solely in the browser is enforced only for users who are
 * using the browser.
 *
 * ---------------------------------------------------------------------------
 * MESSAGES NAME THE PROBLEM
 * ---------------------------------------------------------------------------
 * Each reason maps to a specific, actionable message. A generic "invalid input"
 * leaves a legitimate user who pasted a formatted spec with no idea what to
 * change, and they will either guess or give up. The messages avoid revealing
 * thresholds, so they help a person without handing a script a target.
 */
class CleanText implements ValidationRule
{
    public function __construct(
        /** Enforce a minimum substance check as well as the spam heuristics. */
        private readonly bool $requireSubstance = false,
        private readonly ?int $minLength = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if ($this->requireSubstance && SuspiciousContent::isTooThin($value, $this->minLength)) {
            $fail('The :attribute is too short to be useful. Please describe what you need in a little more detail.');

            return;
        }

        $reasons = SuspiciousContent::reasons($value);

        if ($reasons === []) {
            return;
        }

        // Report one reason, the first. Listing every rule a submission broke reads
        // as an accusation and, more practically, tells an author exactly which
        // combination of filters to tune against.
        $fail(match ($reasons[0]) {
            'markup' => 'The :attribute cannot contain HTML, script or template code. Please use plain text.',
            'excessive_links' => 'The :attribute contains too many links. Please remove some and describe your requirement in words.',
            'repeated_characters' => 'The :attribute contains long runs of repeated characters. Please rewrite it normally.',
            'excessive_capitals' => 'The :attribute is mostly capital letters. Please use normal sentence case.',
            default => 'The :attribute could not be accepted. Please rewrite it and try again.',
        });
    }
}
