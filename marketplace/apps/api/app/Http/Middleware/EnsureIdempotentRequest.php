<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a repeated write safe to send twice.
 *
 * ---------------------------------------------------------------------------
 * THE PROBLEM
 * ---------------------------------------------------------------------------
 * A user double-clicks "Place order". Or their connection drops after the
 * request arrives but before the response returns, and the browser retries. Or
 * they have the checkout open in two tabs. All three produce two identical
 * requests, and without protection all three produce two orders.
 *
 * Disabling the button in the frontend handles the first case only, and only
 * while the JavaScript is behaving. It is a usability improvement, not a
 * guarantee, and it is worthless against a retry the browser performs itself.
 * The guarantee has to be server-side.
 *
 * ---------------------------------------------------------------------------
 * HOW IT WORKS
 * ---------------------------------------------------------------------------
 * The client sends an `Idempotency-Key` it generated for that user intent. The
 * first request through claims the key and runs. A second request with the same
 * key does not run — it replays the first response.
 *
 * The claim is atomic (`Cache::add`, a single compare-and-set), which matters
 * because the two racing requests may be in flight simultaneously. A
 * check-then-write would let both pass the check before either wrote, which is
 * exactly the double-submit this exists to stop.
 *
 * ---------------------------------------------------------------------------
 * WHY A 409 WHILE IN FLIGHT
 * ---------------------------------------------------------------------------
 * If the key is claimed but no response is stored yet, the first request is
 * still running. The second gets 409 rather than waiting, because holding a
 * request open to poll for another one's result ties up a worker and can deadlock
 * under load. 409 tells an honest client to back off and re-read state.
 *
 * ---------------------------------------------------------------------------
 * SCOPE
 * ---------------------------------------------------------------------------
 * Keys are namespaced per user and per route. The same key from two different
 * accounts is two different intents, and reusing a key across endpoints must not
 * replay an unrelated response.
 *
 * Only successful responses (2xx) are stored. Replaying a failure would prevent
 * a legitimate retry after a transient error, which is the one case where the
 * client is right to try again with the same key.
 */
class EnsureIdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $header = (string) config('antispam.idempotency.header', 'Idempotency-Key');
        $key = $request->header($header);

        /*
         * No key means no protection, and that is accepted rather than enforced.
         *
         * Requiring the header would break every existing client the moment this
         * shipped, including the current frontend and the whole test suite. It is
         * opt-in: clients that send a key get the guarantee, and the endpoints where
         * it matters most send one. Making it mandatory is a follow-up that needs a
         * deprecation window, not something to slip into a redesign.
         */
        if (! is_string($key) || trim($key) === '') {
            return $next($request);
        }

        // Bounded and sanitised: the key becomes part of a cache key, so an
        // unbounded or structured value is both a storage and an injection concern.
        $key = substr(preg_replace('/[^A-Za-z0-9\-_]/', '', $key) ?? '', 0, 128);

        if ($key === '') {
            return $next($request);
        }

        $cacheKey = sprintf(
            'idem:%s:%s:%s',
            $request->user()?->getAuthIdentifier() ?? 'guest-'.$request->ip(),
            sha1($request->method().'|'.$request->path()),
            $key,
        );

        $ttl = now()->addMinutes((int) config('antispam.idempotency.ttl_minutes', 1440));

        // Atomic claim. Returns false if another request already holds this key.
        if (! Cache::add($cacheKey.':lock', true, $ttl)) {
            $stored = Cache::get($cacheKey.':response');

            if (is_array($stored)) {
                return response()->json($stored['body'], $stored['status'])
                    // Tells an honest client this was a replay and not a second
                    // effect, which it otherwise cannot distinguish.
                    ->header('Idempotent-Replay', 'true');
            }

            return response()->json([
                'success' => false,
                'message' => 'An identical request is already being processed. Please wait a moment before retrying.',
                'errors' => [],
            ], 409);
        }

        $response = $next($request);

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300 && method_exists($response, 'getContent')) {
            $decoded = json_decode((string) $response->getContent(), true);

            if (is_array($decoded)) {
                Cache::put($cacheKey.':response', ['status' => $status, 'body' => $decoded], $ttl);
            }
        } else {
            // Release the claim so a genuine retry after a failure can proceed.
            Cache::forget($cacheKey.':lock');
        }

        return $response;
    }
}
