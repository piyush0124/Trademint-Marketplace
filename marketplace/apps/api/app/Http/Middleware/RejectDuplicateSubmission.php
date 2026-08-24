<?php

namespace App\Http\Middleware;

use App\Support\Spam\SpamLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects the same payload submitted twice by the same client in a short window.
 *
 * ---------------------------------------------------------------------------
 * HOW THIS DIFFERS FROM EnsureIdempotentRequest
 * ---------------------------------------------------------------------------
 * They look similar and solve different problems, which is why both exist.
 *
 * Idempotency is client-declared and *cooperative*: the client says "these two
 * requests are the same intent" by sending one key, and gets the first response
 * replayed. It protects the honest client from double effects.
 *
 * This is server-inferred and *adversarial*: nobody declared anything, and the
 * content itself is the evidence. It catches the bot posting one identical
 * enquiry body to forty suppliers — which idempotency will happily allow, since
 * each of those requests is a genuinely distinct intent with its own key, or no
 * key at all.
 *
 * ---------------------------------------------------------------------------
 * WHAT GOES INTO THE HASH
 * ---------------------------------------------------------------------------
 * Only the text fields worth comparing, sorted for stability, and normalised for
 * case and whitespace first. Two consequences of that choice:
 *
 *   - Hashing the whole payload would make the check useless, because a single
 *     differing id — the supplier being contacted — changes the hash, and that
 *     is precisely the field that varies in a spray attack.
 *   - Normalising means "BUY NOW" and "buy   now" collide, so trivial
 *     reformatting does not defeat it.
 *
 * ---------------------------------------------------------------------------
 * WHY 429 AND NOT 422
 * ---------------------------------------------------------------------------
 * Nothing is wrong with the submission's *shape*, so a validation error would be
 * misleading. The client is being asked to slow down, which is what 429 means,
 * and honest clients already know how to handle it.
 */
class RejectDuplicateSubmission
{
    /**
     * Fields compared across submissions. Free text only: these are what a human
     * writes and a script repeats.
     */
    private const TEXT_FIELDS = [
        'title', 'description', 'message', 'customer_message', 'business_message',
        'comment', 'body', 'notes', 'reason', 'about', 'input',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('antispam.duplicates.enabled', true)) {
            return $next($request);
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $fingerprint = $this->fingerprint($request);

        if ($fingerprint === null) {
            return $next($request);
        }

        $window = (int) config('antispam.duplicates.window_minutes', 10);

        // Atomic: two identical requests racing must not both pass.
        if (! Cache::add($fingerprint, true, now()->addMinutes($window))) {
            SpamLog::record($request, 'duplicate_submission');

            return response()->json([
                'success' => false,
                'message' => 'You have already submitted this. Please change the details or try again later.',
                'errors' => [],
            ], 429);
        }

        $response = $next($request);

        /*
         * A rejected submission must not hold the slot.
         *
         * Otherwise a validation failure would block the user's corrected retry —
         * they fix one field, resubmit, and get told it is a duplicate of the
         * attempt that never succeeded. Only a submission that actually landed
         * should occupy the window.
         */
        if ($response->getStatusCode() >= 300) {
            Cache::forget($fingerprint);
        }

        return $response;
    }

    private function fingerprint(Request $request): ?string
    {
        $parts = [];

        foreach (self::TEXT_FIELDS as $field) {
            $value = $request->input($field);

            if (is_string($value) && trim($value) !== '') {
                $parts[$field] = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
            }
        }

        // Nothing textual to compare — this is a state-change endpoint, not a
        // content submission, and duplicate detection does not apply.
        if ($parts === []) {
            return null;
        }

        ksort($parts);

        $actor = $request->user()?->getAuthIdentifier() ?? 'ip-'.$request->ip();

        // Route is *not* part of the key, on purpose: the same body posted to forty
        // different supplier endpoints is the exact pattern being caught, and
        // including the path would let each one through.
        return 'dupe:'.$actor.':'.sha1(json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
