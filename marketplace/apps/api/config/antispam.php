<?php

/*
|--------------------------------------------------------------------------
| Anti-spam and abuse configuration
|--------------------------------------------------------------------------
|
| Every threshold the abuse layer uses lives here, so tuning a limit after a
| real incident is a config change rather than a code change, and so the whole
| policy can be read in one place during a review.
|
| Design notes that are easy to get wrong:
|
| - Limits are expressed per-route-group, not globally. One global limit is
|   either too loose for signup or too tight for search; it cannot be both
|   right. Search fires per keystroke, signup should not fire twice a minute.
|
| - Every limit is keyed on the authenticated user where one exists and on IP
|   otherwise. Keying purely on IP punishes everyone behind a corporate NAT or
|   a mobile carrier gateway, which in India is a large share of legitimate
|   traffic. Keying purely on the user id is useless against signup abuse,
|   because there is no user yet.
|
| - CAPTCHA is deliberately absent from the default path. It is configured but
|   disabled, and intended only as an escalation once a client has already
|   tripped the cheaper signals below. Making every buyer solve a puzzle to
|   send an enquiry costs more real business than the spam it prevents.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Named rate limiters
    |--------------------------------------------------------------------------
    |
    | `attempts` per `decay` minutes. Registered in RateLimitServiceProvider and
    | applied with `throttle:<name>` in routes/api.php.
    |
    | `by_ip` limits are the ceiling for unauthenticated callers on that route.
    | Where a route is authenticated, the user limit applies and the IP limit is
    | a second, looser ceiling that catches one machine driving many accounts.
    */
    'limiters' => [

        /*
         * Pre-authentication surfaces, all pinned to `scope => 'ip'`.
         *
         * These must never key on the authenticated user, and the reason is not
         * cosmetic. `POST /auth/register` exists to *create* the identity, so
         * keying its limit on that identity means every attempt lands in a fresh
         * bucket and the limit counts to one, forever. That is exactly what
         * happened before this flag existed — the signup limiter registered,
         * reported `limit=3`, and let an unbounded number of registrations
         * through, because each request's key contained a different user id.
         *
         * For these routes the IP is the only stable identifier available at the
         * moment the decision has to be made.
         */

        // Login. Tight, because this is the credential-stuffing surface. Laravel's
        // own ThrottlesLogins is not in play here (this is a token API), so this is
        // the only brake.
        'auth' => ['attempts' => 5, 'decay' => 1, 'by_ip' => 20, 'scope' => 'ip'],

        // Registration. A human signs up once. Anything repeating this is a script.
        'signup' => ['attempts' => 3, 'decay' => 10, 'by_ip' => 8, 'scope' => 'ip'],

        // Password reset. Low, because each attempt can send an email, so abuse
        // here turns our mail reputation into someone else's spam complaint.
        'password-reset' => ['attempts' => 3, 'decay' => 15, 'by_ip' => 6, 'scope' => 'ip'],

        // Requirement creation. A genuine buyer posts a handful a day at most.
        'requirements' => ['attempts' => 10, 'decay' => 60, 'by_ip' => 20],

        // Enquiries. Higher, because a buyer legitimately contacts several
        // suppliers about the same requirement in one sitting.
        'enquiries' => ['attempts' => 20, 'decay' => 60, 'by_ip' => 40],

        // Quotes. A busy supplier does send many, so this is generous; it exists
        // to stop a loop, not to pace a working day.
        'quotes' => ['attempts' => 30, 'decay' => 60, 'by_ip' => 60],

        // Reviews. The hard rule is one review per completed order, enforced in
        // ReviewService. This limiter only stops someone hammering the endpoint.
        'reviews' => ['attempts' => 5, 'decay' => 60, 'by_ip' => 15],

        // Listing writes.
        'listings' => ['attempts' => 30, 'decay' => 60, 'by_ip' => 60],

        // Search and browse. Deliberately high: this is the one surface where a
        // legitimate user really does make dozens of requests in a minute.
        'search' => ['attempts' => 90, 'decay' => 1, 'by_ip' => 120],

        // File upload. Low count, because each one costs disk and scan time.
        'uploads' => ['attempts' => 20, 'decay' => 10, 'by_ip' => 30],

        // Guest lead form on a public listing — no account required, so IP is the
        // only key available and it has to be tight.
        'contact' => ['attempts' => 5, 'decay' => 30, 'by_ip' => 10, 'scope' => 'ip'],

        // Orders and payments. Money endpoints; idempotency is the real protection
        // (see below), this is the blunt backstop.
        'payments' => ['attempts' => 15, 'decay' => 10, 'by_ip' => 30],
    ],

    /*
    |--------------------------------------------------------------------------
    | Honeypot
    |--------------------------------------------------------------------------
    |
    | A field that is hidden from humans and left empty by them. Anything that
    | fills it is filling fields it cannot see.
    |
    | The field name is intentionally plausible rather than obviously a trap:
    | naming it `honeypot` tells a bot exactly what to skip. It must stay in
    | sync with the frontend constant in apps/web/src/lib/antiSpam.ts.
    */
    'honeypot' => [
        'enabled' => env('ANTISPAM_HONEYPOT_ENABLED', true),
        'field' => 'contact_reference',

        // Companion timestamp field. The form records when it was rendered; a
        // submission arriving impossibly fast was not typed by a person.
        'timestamp_field' => 'form_loaded_at',

        // Seconds. Deliberately low — 2s is below any realistic human completion
        // time for these forms but high enough that a fast autofill user, or
        // someone who returns to a pre-filled tab, is not blocked.
        'min_seconds' => env('ANTISPAM_MIN_SECONDS', 2),

        // Seconds. A form open for longer than this is stale; its CSRF/session
        // context is likely gone and re-rendering is the honest outcome.
        'max_seconds' => env('ANTISPAM_MAX_SECONDS', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate submission detection
    |--------------------------------------------------------------------------
    |
    | Two layers, doing different jobs:
    |
    | `idempotency` is for money and state: the client sends a key, and a repeat
    | of the same key returns the first response instead of acting twice. This
    | is what protects against a double-click, a retried request on a flaky
    | connection, and a duplicated browser tab.
    |
    | `content_hash` is for text: the same payload from the same user inside the
    | window is rejected. This catches a bot posting the same enquiry to fifty
    | suppliers, which idempotency would not, because each of those requests is
    | legitimately distinct as far as the client is concerned.
    */
    'idempotency' => [
        'header' => 'Idempotency-Key',
        // Long enough to cover a user retrying after a timeout, short enough that
        // a legitimate second order for the same thing later is not swallowed.
        'ttl_minutes' => 1440,
    ],

    'duplicates' => [
        'enabled' => true,
        'window_minutes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious content heuristics
    |--------------------------------------------------------------------------
    |
    | Applied to free-text the user writes. These flag and reject the obvious
    | cases only. They are intentionally not clever: a heuristic that guesses at
    | intent will reject a legitimate technical requirement full of URLs and
    | part numbers, and a buyer who cannot post their requirement leaves.
    |
    | Anything subtler than this belongs in moderation, where a human decides,
    | not in a validator that returns 422.
    */
    'content' => [
        // More than this many links in one submission is advertising, not a brief.
        'max_links' => 4,

        // "Buyyyyy nowwwww" — the same character eight or more times running.
        'max_repeated_chars' => 7,

        // Fraction of letters that are capitals, above which the text is shouting.
        // Only applied past a minimum length so "ASAP" or "GST" cannot trip it.
        'max_uppercase_ratio' => 0.6,
        'uppercase_min_length' => 40,

        // Below this, a "description" carries no information a supplier can quote
        // against. Kept low because terse is not the same as spam.
        'min_meaningful_length' => 15,

        /*
         * Markup is rejected outright rather than sanitised.
         *
         * These fields are rendered as plain text everywhere in the product, so
         * there is no legitimate reason for a tag to appear in one. Rejecting is
         * safer than stripping: a sanitiser that silently rewrites input is a
         * sanitiser someone will eventually find a way through, and the user
         * never learns their input was altered.
         */
        'reject_html' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious activity logging
    |--------------------------------------------------------------------------
    |
    | Written to the existing `audit_logs` table under a `spam.*` action prefix.
    |
    | A separate table was considered and rejected: two append-only logs of
    | security-relevant events means two places to look during an incident, and
    | eventually two answers to the same question. `audit_logs` is already
    | append-only, already immutable at the model level, and already surfaced in
    | the admin UI.
    */
    'logging' => [
        'enabled' => true,
        // Never log the offending text itself. A spam log full of payloads is a
        // stored-XSS vector aimed at whoever reads the admin panel.
        'log_payload' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA (escalation only)
    |--------------------------------------------------------------------------
    |
    | Off by default and deliberately so. When enabled, it is required only from
    | clients that have already tripped `escalate_after` cheaper signals, so a
    | normal user never sees one.
    */
    'captcha' => [
        'enabled' => env('ANTISPAM_CAPTCHA_ENABLED', false),
        'provider' => env('ANTISPAM_CAPTCHA_PROVIDER', 'turnstile'),
        'secret' => env('ANTISPAM_CAPTCHA_SECRET'),
        'escalate_after' => 3,
    ],
];
