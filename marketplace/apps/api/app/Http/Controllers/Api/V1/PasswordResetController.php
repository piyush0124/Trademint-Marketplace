<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password reset.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * Until now there was no reset route at all, so a user who forgot their password
 * was permanently locked out of an account holding their orders and payment
 * history, with no path back other than asking an admin to intervene in the
 * database. On a platform that handles money that is not a missing nicety, it is
 * an active failure.
 *
 * ---------------------------------------------------------------------------
 * NEUTRAL RESPONSES: THE CENTRAL DECISION
 * ---------------------------------------------------------------------------
 * `forgotPassword` returns the same 200 and the same message for every input:
 * a registered address, an unregistered one, and a request that the broker
 * throttled.
 *
 * Anything else is an account-enumeration oracle. "No account with that email"
 * lets anyone test an address list against the platform and learn which
 * businesses are registered here, without ever guessing a password — and on a B2B
 * marketplace that list is commercially interesting on its own. Even the
 * throttle response leaks, because being throttled implies a user record exists
 * to throttle against.
 *
 * This matches the decision already made for login, which returns one neutral
 * "Invalid email or password" for both an unknown address and a wrong password
 * (see AuthController::login and its test).
 *
 * The cost is real and accepted: someone who mistypes their address gets a
 * success message and no email. The message is worded to make that recoverable
 * ("if an account exists") rather than promising delivery.
 */
class PasswordResetController extends Controller
{
    /** The one response `forgotPassword` is allowed to give. */
    private const NEUTRAL_MESSAGE = 'If an account exists for that email address, a reset link is on its way. Please check your inbox and spam folder.';

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->validated('email')));

        /*
         * The broker's status is deliberately discarded.
         *
         * sendResetLink returns RESET_LINK_SENT, INVALID_USER or RESET_THROTTLED.
         * Branching on it is exactly the leak described above, so all three produce
         * the identical response below.
         */
        Password::sendResetLink(['email' => $email]);

        return response()->json([
            'success' => true,
            'message' => self::NEUTRAL_MESSAGE,
            'data' => null,
        ]);
    }

    /**
     * Complete the reset.
     *
     * Unlike the request step, this one *does* report failure specifically, and
     * that is not inconsistent. Reaching here requires possession of a token, so
     * there is nothing left to enumerate — and a user holding an expired link needs
     * to be told it expired, or they will keep retrying the same dead link.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email' => strtolower(trim($request->validated('email'))),
                'password' => $request->validated('password'),
                'password_confirmation' => $request->validated('password_confirmation'),
                'token' => $request->validated('token'),
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    // Hashed by the model's `password => hashed` cast.
                    'password' => $password,
                    // Rotated so any "remember me" cookie issued before the reset
                    // stops working.
                    'remember_token' => Str::random(60),
                ])->save();

                /*
                 * Revoke every API token this user holds.
                 *
                 * A password reset is frequently a response to a suspected
                 * compromise, and leaving existing Sanctum tokens valid would mean
                 * the attacker keeps their session while the victim changes a
                 * password that no longer protects anything. Signing every device
                 * out is the only behaviour consistent with the reason people do
                 * this.
                 *
                 * The trade-off — the user is logged out on their other devices —
                 * is the correct surprise to cause.
                 */
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            /*
             * One message for an invalid token and an expired one.
             *
             * The remedy is identical — request a fresh link — and distinguishing
             * them tells someone probing tokens whether a given value was ever
             * valid.
             */
            throw ValidationException::withMessages([
                'token' => ['This reset link is invalid or has expired. Please request a new one.'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset. Please sign in with your new password.',
            'data' => null,
        ]);
    }

    /**
     * Whether a token is worth showing a form for.
     *
     * Lets the SPA fail fast on an expired link instead of asking the user to
     * choose and confirm a new password, submit, and only then be told the link
     * was dead. It reports nothing the caller does not already hold.
     */
    public function verifyToken(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->validated('email')));
        $token = (string) $request->input('token');

        $user = User::query()->where('email', $email)->first();

        $valid = $user !== null
            && $token !== ''
            && Password::broker()->tokenExists($user, $token);

        return response()->json([
            'success' => true,
            'message' => $valid ? 'Token is valid.' : 'Token is invalid or has expired.',
            'data' => ['valid' => $valid],
        ]);
    }

    /**
     * Housekeeping helper for the scheduler.
     *
     * Expired rows in `password_reset_tokens` are harmless but unbounded, and the
     * table is one an auditor will look at.
     */
    public static function pruneExpiredTokens(): int
    {
        $cutoff = now()->subMinutes((int) config('auth.passwords.users.expire', 60));

        return DB::table('password_reset_tokens')->where('created_at', '<', $cutoff)->delete();
    }
}
