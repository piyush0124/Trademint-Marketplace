<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Issues the signed timestamp that DetectSpamSubmission verifies.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SERVER HAS TO MINT THIS
 * ---------------------------------------------------------------------------
 * The minimum-submission-time check is only meaningful if the client cannot
 * choose the time. Letting the browser send its own `form_loaded_at` would mean
 * a bot sends "ten minutes ago" and the check becomes decoration — it would
 * reject nothing while adding a field to every form.
 *
 * So the token is `<unix-seconds>.<hmac>`, signed with the application key. A
 * client can hold it, replay it, or discard it, but it cannot make a fresh form
 * look older than it is.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES NOT TRY TO BE
 * ---------------------------------------------------------------------------
 * Not a CSRF token and not a nonce. It is not single-use, and a client may reuse
 * one across several forms in a session. Making it single-use would require
 * server-side state per rendered form — a write on every page view — to defend
 * against an attacker who can simply request a new token anyway.
 *
 * Its only job is to make the *age* of a form unforgeable. Duplicate and volume
 * abuse are handled by the layers built for those, not by this.
 *
 * Unauthenticated on purpose: the guest lead form on a public listing is exactly
 * where this matters most.
 */
class FormTokenController extends Controller
{
    public function show(): JsonResponse
    {
        $issued = (string) time();

        return response()->json([
            'success' => true,
            'data' => [
                'field' => config('antispam.honeypot.timestamp_field'),
                'token' => $issued.'.'.hash_hmac('sha256', $issued, (string) config('app.key')),
                // Lets the client discard a stale token and fetch a fresh one rather
                // than submitting one the server will reject as expired.
                'expires_in' => (int) config('antispam.honeypot.max_seconds', 86400),
                'honeypot_field' => config('antispam.honeypot.field'),
            ],
        ]);
    }
}
