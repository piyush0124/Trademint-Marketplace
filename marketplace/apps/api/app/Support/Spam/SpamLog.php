<?php

namespace App\Support\Spam;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Records suspicious activity into the existing audit trail.
 *
 * ---------------------------------------------------------------------------
 * WHY audit_logs AND NOT A NEW TABLE
 * ---------------------------------------------------------------------------
 * A dedicated `spam_logs` table was the obvious first move and is the wrong one.
 * Two append-only logs of security-relevant events means two places to search
 * during an incident, two retention policies, and eventually two different
 * answers to "what did this account do on Tuesday".
 *
 * `audit_logs` is already append-only, already immutable at the model level
 * (see AuditLog::booted), already indexed by actor and entity, and already
 * rendered in the admin UI. A `spam.*` action prefix is enough to separate these
 * entries from ordinary ones, and it keeps one timeline per account.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS NOT RECORDED
 * ---------------------------------------------------------------------------
 * The offending text. A spam log full of attacker-controlled payloads is a
 * stored-XSS vector pointed directly at whoever opens the admin panel — the one
 * user on the platform with the most authority. Only the *reason* codes and
 * request shape are kept, which is what an operator actually needs.
 */
class SpamLog
{
    /**
     * @param  list<string>  $reasons  Machine-readable codes, never user text.
     */
    public static function record(Request $request, string $event, array $reasons = [], array $context = []): void
    {
        if (! config('antispam.logging.enabled', true)) {
            return;
        }

        $user = $request->user();

        /*
         * Failures are swallowed on purpose.
         *
         * This runs inside the request path of a submission that is already being
         * rejected. If logging itself fails — a locked table, a full disk — the
         * correct outcome is still a clean 422 for the caller, not a 500 that
         * exposes the logging failure and tells an attacker they found a way to
         * break the observer.
         */
        try {
            AuditLog::query()->create([
                'user_id' => $user?->getAuthIdentifier(),
                'actor_role' => $user?->role?->value ?? 'guest',
                'action' => 'spam.'.$event,
                'entity_type' => 'request',
                'entity_id' => null,
                'entity_reference' => substr($request->path(), 0, 190),
                'ip_address' => $request->ip(),
                'metadata' => array_merge([
                    'reasons' => $reasons,
                    'method' => $request->method(),
                    // Truncated: a crafted 8KB User-Agent should not become an 8KB
                    // audit row on every request in a flood.
                    'user_agent' => substr((string) $request->userAgent(), 0, 190),
                ], $context),
            ]);
        } catch (\Throwable) {
            // Intentionally ignored — see above.
        }

        self::countStrike($request);
    }

    /**
     * A short-lived per-client counter, used to decide whether to escalate.
     *
     * Cache rather than the database on purpose, and this is the opposite of the
     * call made for AI quotas. A quota is a limit and must survive a restart, so
     * it lives in the database. This counter is a transient signal about a client
     * that is misbehaving *right now*; losing it on a restart just means the
     * client starts from a clean slate, which is an acceptable outcome and far
     * cheaper than a write per rejected request during a flood.
     */
    private static function countStrike(Request $request): void
    {
        $key = self::strikeKey($request);

        Cache::add($key, 0, now()->addHour());
        Cache::increment($key);
    }

    public static function strikes(Request $request): int
    {
        return (int) Cache::get(self::strikeKey($request), 0);
    }

    /**
     * Whether this client has misbehaved enough to warrant a CAPTCHA.
     *
     * The point of gating escalation behind a strike count is that a normal user
     * never reaches it. Showing everyone a puzzle to prevent spam that the
     * cheaper checks already catch costs more real business than it saves.
     */
    public static function shouldEscalate(Request $request): bool
    {
        if (! config('antispam.captcha.enabled', false)) {
            return false;
        }

        return self::strikes($request) >= (int) config('antispam.captcha.escalate_after', 3);
    }

    private static function strikeKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null
            ? 'spam:strikes:user:'.$user->getAuthIdentifier()
            : 'spam:strikes:ip:'.$request->ip();
    }
}
