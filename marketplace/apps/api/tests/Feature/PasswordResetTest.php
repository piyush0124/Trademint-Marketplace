<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'buyer@example.test',
            'password' => 'old-password-123',
            'role' => UserRole::Customer,
        ]);

        // The password-reset limiter is keyed on IP and persists in the cache
        // between tests in one process, so one test exhausting it would fail the next.
        Cache::flush();
    }

    /** Obtain a real token the way the application does. */
    private function tokenFor(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    // ------------------------------------------------------------- requesting

    public function test_a_registered_user_receives_a_reset_link(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'buyer@example.test'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
    }

    /** Case and surrounding whitespace must not decide whether a reset works. */
    public function test_the_email_is_matched_case_insensitively(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => '  BUYER@Example.TEST '])
            ->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
    }

    /**
     * The central security property.
     *
     * An unknown address must produce a byte-identical response to a known one.
     * Anything else lets an attacker test an address list against the platform and
     * learn which businesses are registered, without guessing a password.
     */
    public function test_an_unknown_email_is_indistinguishable_from_a_known_one(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'buyer@example.test']);
        Cache::flush();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());

        // And nothing was sent for the address that does not exist.
        Notification::assertSentToTimes($this->user, ResetPasswordNotification::class, 1);
    }

    /**
     * A validation rule of `exists:users,email` would be the obvious thing to add
     * and would reintroduce the leak as a 422. Pinned so it cannot come back.
     */
    public function test_an_unknown_email_does_not_produce_a_validation_error(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonMissingPath('errors.email');
    }

    public function test_a_malformed_email_is_still_rejected(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($m) => is_string($m));
    }

    /** Each accepted request can send mail, so the endpoint is tightly limited. */
    public function test_reset_requests_are_rate_limited(): void
    {
        Notification::fake();

        $limit = (int) config('antispam.limiters.password-reset.attempts');

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'buyer@example.test'])
                ->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'buyer@example.test'])
            ->assertStatus(429);
    }

    // -------------------------------------------------------------- resetting

    public function test_a_valid_token_resets_the_password(): void
    {
        $token = $this->tokenFor($this->user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('brand-new-password', $this->user->fresh()->password));
    }

    public function test_the_new_password_works_for_signing_in(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->tokenFor($this->user),
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        Cache::flush();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_the_old_password_stops_working(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->tokenFor($this->user),
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        Cache::flush();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.test',
            'password' => 'old-password-123',
        ])->assertStatus(422);
    }

    /**
     * A reset is usually a response to a suspected compromise. Leaving existing
     * tokens valid would let an attacker keep their session while the victim
     * changes a password that no longer protects anything.
     */
    public function test_resetting_revokes_every_existing_api_token(): void
    {
        $this->user->createToken('phone');
        $this->user->createToken('laptop');
        $this->assertSame(2, $this->user->tokens()->count());

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->tokenFor($this->user),
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertSame(0, $this->user->tokens()->count());
    }

    /** Single use — a leaked link must not be replayable. */
    public function test_a_token_cannot_be_used_twice(): void
    {
        $token = $this->tokenFor($this->user);
        $payload = [
            'token' => $token,
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        $this->postJson('/api/v1/auth/reset-password', array_merge($payload, [
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ]))->assertStatus(422);
    }

    public function test_a_forged_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password-123', $this->user->fresh()->password));
    }

    /** A token issued for one account must not reset another. */
    public function test_a_token_cannot_be_used_against_a_different_account(): void
    {
        $other = User::factory()->create(['email' => 'other@example.test', 'password' => 'other-password']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->tokenFor($this->user),
            'email' => 'other@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('other-password', $other->fresh()->password));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->tokenFor($this->user);

        // Age the row past config('auth.passwords.users.expire') minutes.
        DB::table('password_reset_tokens')
            ->where('email', 'buyer@example.test')
            ->update(['created_at' => now()->subMinutes((int) config('auth.passwords.users.expire') + 5)]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password-123', $this->user->fresh()->password));
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->tokenFor($this->user),
            'email' => 'buyer@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'different-password',
        ])->assertStatus(422)->assertJsonPath('errors.password.0', 'Both passwords must match.');
    }

    /** Matches registration's rule; a stricter policy here would strand the user. */
    public function test_a_short_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->tokenFor($this->user),
            'email' => 'buyer@example.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------ token pre-flight

    public function test_the_verify_endpoint_recognises_a_good_token(): void
    {
        $this->postJson('/api/v1/auth/reset-password/verify', [
            'email' => 'buyer@example.test',
            'token' => $this->tokenFor($this->user),
        ])->assertOk()->assertJsonPath('data.valid', true);
    }

    public function test_the_verify_endpoint_rejects_a_bad_token(): void
    {
        $this->postJson('/api/v1/auth/reset-password/verify', [
            'email' => 'buyer@example.test',
            'token' => 'rubbish',
        ])->assertOk()->assertJsonPath('data.valid', false);
    }

    /** Pre-flight must not become the enumeration oracle the request step avoids. */
    public function test_the_verify_endpoint_reports_invalid_for_an_unknown_account(): void
    {
        $this->postJson('/api/v1/auth/reset-password/verify', [
            'email' => 'nobody@example.test',
            'token' => 'anything',
        ])->assertOk()->assertJsonPath('data.valid', false);
    }

    // ------------------------------------------------------------------ mail

    /**
     * The link must point at the SPA, not at this API.
     *
     * Laravel's default notification builds a `password.reset` Blade route that does
     * not exist here, so a regression would email users a 404.
     */
    public function test_the_emailed_link_points_at_the_frontend_with_token_and_email(): void
    {
        config()->set('marketplace.frontend_url', 'https://app.example.test');

        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'buyer@example.test'])->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordNotification::class, function ($notification) {
            $mail = $notification->toMail($this->user);
            $url = $mail->actionUrl;

            $this->assertStringStartsWith('https://app.example.test/reset-password?', $url);
            $this->assertStringContainsString('token='.$notification->token, $url);
            $this->assertStringContainsString('email='.urlencode('buyer@example.test'), $url);

            return true;
        });
    }
}
