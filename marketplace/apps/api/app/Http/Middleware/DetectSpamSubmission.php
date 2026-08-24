<?php

namespace App\Http\Middleware;

use App\Support\Spam\SpamLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two cheap bot signals, applied before a controller runs.
 *
 * ---------------------------------------------------------------------------
 * 1. HONEYPOT
 * ---------------------------------------------------------------------------
 * A field hidden from humans that a bot fills because it parses the DOM and
 * completes everything it finds. It costs a legitimate user nothing — no puzzle,
 * no extra step, no accessibility burden, provided the field is hidden properly
 * (see the frontend helper: `aria-hidden` plus `tabindex="-1"`, so assistive
 * technology skips it too rather than reading out a mystery input).
 *
 * The field is named `contact_reference`, not `honeypot`. A trap labelled as a
 * trap is one a bot author skips in an afternoon.
 *
 * ---------------------------------------------------------------------------
 * 2. MINIMUM SUBMISSION TIME
 * ---------------------------------------------------------------------------
 * The form records when it was rendered. A submission arriving less than two
 * seconds later was not read and typed by a person.
 *
 * The timestamp is signed, and that detail is the whole point: an unsigned
 * client-supplied timestamp is trivially set to "ten minutes ago" by the exact
 * adversary this is meant to stop, which makes the check theatre. Signing it
 * means the client cannot forge an age without the app key.
 *
 * ---------------------------------------------------------------------------
 * WHY THESE FAIL OPEN WHEN THE FIELDS ARE ABSENT
 * ---------------------------------------------------------------------------
 * A missing timestamp is not treated as a violation. This API has non-browser
 * clients — the mobile app, integration tests, and eventually partner
 * integrations — and none of them render a form. Hard-failing on an absent field
 * would break every one of them to catch a bot that could simply supply the
 * field anyway. The honeypot is the check that does not depend on client
 * cooperation; timing is a bonus signal when a real form was used.
 */
class DetectSpamSubmission
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('antispam.honeypot.enabled', true)) {
            return $next($request);
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $config = (array) config('antispam.honeypot', []);
        $honeypotField = $config['field'] ?? 'contact_reference';
        $timestampField = $config['timestamp_field'] ?? 'form_loaded_at';

        // --- Honeypot ---
        $trap = $request->input($honeypotField);

        if (is_string($trap) && trim($trap) !== '') {
            SpamLog::record($request, 'honeypot_filled');

            return $this->reject();
        }

        // --- Submission timing ---
        $stamp = $request->input($timestampField);

        if (is_string($stamp) && $stamp !== '') {
            $age = $this->ageInSeconds($stamp);

            if ($age === null) {
                // Unverifiable signature: the value was tampered with, or issued by
                // a different app key. Either way it cannot be trusted as evidence,
                // so it is treated as absent rather than as proof of guilt.
                SpamLog::record($request, 'timestamp_invalid');
            } elseif ($age < (int) ($config['min_seconds'] ?? 2)) {
                SpamLog::record($request, 'submitted_too_fast', ['seconds' => $age]);

                return $this->reject();
            } elseif ($age > (int) ($config['max_seconds'] ?? 86400)) {
                SpamLog::record($request, 'form_expired', ['seconds' => $age]);

                return response()->json([
                    'success' => false,
                    'message' => 'This form has been open too long. Please reload the page and try again.',
                    'errors' => [],
                ], 422);
            }
        }

        // The trap field must not reach validation or a model. Leaving it in the bag
        // would surface it in `$request->all()`, and any controller doing a mass
        // assignment from that would try to write a column that does not exist.
        $request->request->remove($honeypotField);
        $request->request->remove($timestampField);

        return $next($request);
    }

    /**
     * Verify and decode a signed `issuedAt.signature` token.
     *
     * Comparison uses hash_equals to avoid leaking, through timing, how much of a
     * forged signature was correct.
     */
    private function ageInSeconds(string $value): ?int
    {
        if (! str_contains($value, '.')) {
            return null;
        }

        [$issued, $signature] = explode('.', $value, 2);

        if (! ctype_digit($issued)) {
            return null;
        }

        $expected = hash_hmac('sha256', $issued, (string) config('app.key'));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        return max(0, time() - (int) $issued);
    }

    /**
     * A deliberately vague message.
     *
     * "Your honeypot field was filled" tells a bot author precisely which field to
     * leave alone next time. The legitimate user who somehow sees this gets an
     * actionable instruction (retry) without the internals.
     */
    private function reject(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'This submission could not be processed. Please reload the page and try again.',
            'errors' => [],
        ], 422);
    }
}
