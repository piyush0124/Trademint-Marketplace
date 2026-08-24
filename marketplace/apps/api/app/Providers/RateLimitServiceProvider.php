<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Registers one named rate limiter per abuse surface.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT ONE GLOBAL LIMIT
 * ---------------------------------------------------------------------------
 * A single limit cannot be correct for both `POST /auth/login` and
 * `GET /listings`. Search legitimately fires dozens of times a minute as a user
 * types; login should not fire six times. Setting one number for both means
 * either leaving the credential endpoint wide open or breaking type-ahead.
 *
 * ---------------------------------------------------------------------------
 * WHY EVERY LIMITER RETURNS TWO LIMITS
 * ---------------------------------------------------------------------------
 * Each returns a user-keyed limit *and* an IP-keyed one. Laravel applies both,
 * and they fail in different ways on purpose:
 *
 *   - user-keyed only: useless before an account exists, so signup and login
 *     abuse walks straight through.
 *   - IP-keyed only: one office, one college campus or one mobile carrier NAT
 *     shares an address. In India that is a very large share of real traffic,
 *     and a tight IP limit locks out an entire building because of one user.
 *
 * Together, the user limit paces an individual and the looser IP limit catches
 * one machine cycling through many accounts.
 *
 * ---------------------------------------------------------------------------
 * THE AI LIMITER IS NOT HERE
 * ---------------------------------------------------------------------------
 * `throttle:ai` stays in AiServiceProvider, next to the daily spend quota it
 * works with. Moving it here would separate two halves of one policy.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (array_keys((array) config('antispam.limiters', [])) as $name) {
            $this->registerLimiter($name);
        }
    }

    /**
     * Admins are exempt from the *volume* limits, not from authorisation.
     *
     * A moderator working a backlog of flagged listings will click through more
     * records in a minute than any limit here allows, and throttling the people
     * cleaning up abuse is counterproductive. Every admin action is still
     * policy-checked and still written to `audit_logs`.
     */
    private function registerLimiter(string $name): void
    {
        RateLimiter::for($name, function (Request $request) use ($name) {
            $config = (array) config('antispam.limiters.'.$name, []);

            $attempts = (int) ($config['attempts'] ?? 30);
            $decay = (int) ($config['decay'] ?? 1);
            $ipAttempts = (int) ($config['by_ip'] ?? $attempts * 2);

            $user = $request->user();

            if ($user !== null && method_exists($user, 'isAdmin') && $user->isAdmin()) {
                return Limit::none();
            }

            /*
             * Two limits, always both applied, with distinct cache keys.
             *
             * `attempts` is the per-identity allowance. Identity is normally the
             * authenticated user, falling back to the IP — and that fallback is
             * load-bearing. An earlier version applied `attempts` only when a user
             * was present, which meant the tight numbers on `signup` (3) and `auth`
             * (5) applied to nothing at all, because those routes are by definition
             * unauthenticated; the effective login limit was the loose IP ceiling of
             * 20, four times what the config claimed.
             *
             * `by_ip` is a second, looser ceiling per address. On an authenticated
             * route it catches one machine cycling through many accounts. On an
             * anonymous one both limits key on the IP under different names, so the
             * tighter `attempts` trips first and `by_ip` is merely redundant — which
             * is harmless, and keeps one rule for every route.
             */

            /*
             * `scope => 'ip'` forces the identity key to the IP even when a user has
             * somehow been resolved.
             *
             * Required for pre-authentication routes. `POST /auth/register` creates
             * the identity it would otherwise be keyed on, so a user-derived key is a
             * different value on every attempt and the bucket never accumulates —
             * the limiter reports a limit of 3 and enforces nothing. Whether a user
             * appears on such a request at all depends on guard state that this
             * layer should not have to reason about, so the safe key is pinned in
             * config instead of inferred.
             */
            $identity = ($config['scope'] ?? null) !== 'ip' && $user !== null
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip();

            return [
                // Scoped by limiter name so spending the enquiry allowance does not
                // also consume the quote allowance. Without the prefix every route
                // sharing a key would drain one shared bucket.
                Limit::perMinutes($decay, $attempts)->by($name.':id:'.$identity),
                Limit::perMinutes($decay, $ipAttempts)->by($name.':net:'.$request->ip()),
            ];
        });
    }
}
