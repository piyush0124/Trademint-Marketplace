<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use App\Support\Spam\SuspiciousContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Coverage for the abuse layer.
 *
 * Each test names the attack it represents rather than the method it calls, because
 * the question a reviewer has here is "is this shape of abuse stopped", not "does
 * this class have a test".
 */
class AntiSpamProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['role' => UserRole::Customer]);

        // Limiters are keyed per IP and per user and persist in the cache between
        // tests in the same process, so one test exhausting an allowance would fail
        // the next. Cleared here rather than in each test.
        Cache::flush();
        RateLimiter::clear('signup:ip:127.0.0.1');
    }

    /** A signed token of the given age, as FormTokenController would have issued. */
    private function formToken(int $secondsAgo = 30): string
    {
        $issued = (string) (time() - $secondsAgo);

        return $issued.'.'.hash_hmac('sha256', $issued, (string) config('app.key'));
    }

    private function requirementPayload(array $overrides = []): array
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'it-software'],
            ['name' => 'IT & Software', 'is_active' => true]
        );

        return array_merge([
            'category_id' => $category->id,
            'title' => 'Need a website built for our trading business',
            'description' => 'We need a five page corporate website with a contact form and hosting setup.',
            'quantity' => 1,
            'unit' => 'project',
            'budget_min' => 40000,
            'budget_max' => 90000,
        ], $overrides);
    }

    // ---------------------------------------------------------------- honeypot

    /** A bot fills every field it finds in the DOM, including the one humans cannot see. */
    public function test_a_filled_honeypot_field_rejects_the_submission(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                'contact_reference' => 'http://spam.example',
            ]))
            ->assertStatus(422);

        $this->assertDatabaseCount('requirements', 0);
    }

    /** The rejection must not name the trap, or the next bot simply skips it. */
    public function test_the_honeypot_rejection_does_not_reveal_which_field_tripped_it(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                'contact_reference' => 'spam',
            ]));

        $body = strtolower((string) $response->getContent());

        $this->assertStringNotContainsString('honeypot', $body);
        $this->assertStringNotContainsString('contact_reference', $body);
    }

    /** An empty honeypot is the normal case and must pass through untouched. */
    public function test_an_empty_honeypot_field_does_not_block_a_genuine_submission(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                'contact_reference' => '',
                'form_loaded_at' => $this->formToken(45),
            ]))
            ->assertCreated();
    }

    /**
     * The trap field must be stripped before validation.
     *
     * If it survived into the request bag, any controller mass-assigning from it
     * would try to write a column that does not exist.
     */
    public function test_the_honeypot_and_timestamp_fields_never_reach_the_model(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                'contact_reference' => '',
                'form_loaded_at' => $this->formToken(45),
            ]))
            ->assertCreated();

        $this->assertDatabaseCount('requirements', 1);
    }

    // ------------------------------------------------------- submission timing

    /** Nobody reads and completes a requirement form in under a second. */
    public function test_a_submission_faster_than_a_human_is_rejected(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                'form_loaded_at' => $this->formToken(0),
            ]))
            ->assertStatus(422);

        $this->assertDatabaseCount('requirements', 0);
    }

    /**
     * The check is only worth having if the age cannot be chosen by the client.
     * An unsigned timestamp claiming to be old must not be believed.
     */
    public function test_an_unsigned_timestamp_cannot_be_used_to_fake_form_age(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                // Plausible-looking, but not signed with the app key.
                'form_loaded_at' => (time() - 600).'.deadbeefdeadbeef',
            ]))
            ->assertCreated();

        // Accepted (the app has non-browser clients, so this fails open) but recorded,
        // because a forged signature is worth knowing about.
        $this->assertDatabaseHas('audit_logs', ['action' => 'spam.timestamp_invalid']);
    }

    /** A stale form is asked to reload rather than silently accepted. */
    public function test_a_form_left_open_past_its_window_is_asked_to_reload(): void
    {
        config()->set('antispam.honeypot.max_seconds', 60);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                'form_loaded_at' => $this->formToken(3600),
            ]))
            ->assertStatus(422);
    }

    /** Non-browser clients send no timestamp at all and must not be locked out. */
    public function test_a_request_with_no_timestamp_is_allowed(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload())
            ->assertCreated();
    }

    public function test_the_form_token_endpoint_issues_a_token_the_middleware_accepts(): void
    {
        $token = $this->getJson('/api/v1/form-token')
            ->assertOk()
            ->json('data.token');

        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);

        // Issued "now", so it trips the minimum-time floor — proving the signature
        // verifies and the age is being read from it.
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                'form_loaded_at' => $token,
            ]))
            ->assertStatus(422);
    }

    // ------------------------------------------------------------ rate limiting

    /** Repeated registration from one address is a script, not a person. */
    public function test_repeated_signup_attempts_from_one_address_are_throttled(): void
    {
        $limit = (int) config('antispam.limiters.signup.attempts');

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Spam '.$i,
                'email' => "spam{$i}@example.test",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Spam over',
            'email' => 'spam-over@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(429);
    }

    /** Credential stuffing must run out of attempts quickly. */
    public function test_repeated_failed_logins_are_throttled(): void
    {
        $limit = (int) config('antispam.limiters.auth.attempts');

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $this->customer->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->customer->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    /**
     * Limiters must not share a bucket.
     *
     * If they did, a user who exhausted the login allowance would also be unable to
     * search, which is a self-inflicted outage rather than a defence.
     */
    public function test_exhausting_one_limiter_does_not_block_an_unrelated_route(): void
    {
        $limit = (int) config('antispam.limiters.auth.attempts');

        for ($i = 0; $i <= $limit; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'x@example.test', 'password' => 'nope']);
        }

        $this->getJson('/api/v1/listings')->assertOk();
    }

    /** Throttling the moderators cleaning up abuse would be counterproductive. */
    public function test_an_admin_is_exempt_from_volume_limits(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $limit = (int) config('antispam.limiters.search.attempts');

        for ($i = 0; $i < $limit + 5; $i++) {
            $this->actingAs($admin, 'sanctum')->getJson('/api/v1/listings')->assertOk();
        }
    }

    // -------------------------------------------------------------- idempotency

    /** A double-clicked submit sends the same key twice and must create one record. */
    public function test_the_same_idempotency_key_does_not_create_two_requirements(): void
    {
        $payload = $this->requirementPayload();
        $headers = ['Idempotency-Key' => 'abc-123-def'];

        $first = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $payload, $headers)
            ->assertCreated();

        $second = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $payload, $headers)
            ->assertCreated();

        $this->assertDatabaseCount('requirements', 1);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertHeader('Idempotent-Replay', 'true');
    }

    /** Two users sending the same key are two intents, not one. */
    public function test_an_idempotency_key_is_scoped_to_its_user(): void
    {
        $other = User::factory()->create(['role' => UserRole::Customer]);
        $headers = ['Idempotency-Key' => 'shared-key'];

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload(), $headers)
            ->assertCreated();

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload(), $headers)
            ->assertCreated();

        $this->assertDatabaseCount('requirements', 2);
    }

    /** Without a key there is no promise, and existing clients must keep working. */
    public function test_requests_without_an_idempotency_key_are_unaffected(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload(['title' => 'First requirement here']))
            ->assertCreated();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload(['title' => 'Second requirement here']))
            ->assertCreated();

        $this->assertDatabaseCount('requirements', 2);
    }

    /**
     * A failed attempt must release its key.
     *
     * Otherwise the user fixes the validation error, resubmits with the same key,
     * and gets the stored failure replayed forever.
     */
    public function test_a_failed_request_does_not_burn_its_idempotency_key(): void
    {
        $headers = ['Idempotency-Key' => 'retry-me'];

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload(['category_id' => null]), $headers)
            ->assertStatus(422);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload(), $headers)
            ->assertCreated();
    }

    // ------------------------------------------------------ duplicate detection

    /** StoreListingRequest keys off the category slug, not its id. */
    private function listingPayload(string $slug, array $overrides = []): array
    {
        Category::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'is_active' => true]
        );

        return array_merge([
            'category_slug' => $slug,
            'title' => 'Full truckload freight across the northern corridor',
            'description' => 'Dedicated fleet with tracking and insurance on every consignment.',
            'price' => 50000,
        ], $overrides);
    }

    /** The same listing pitch posted twice in a few minutes is a script. */
    public function test_an_identical_text_payload_is_rejected_as_a_duplicate(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $payload = $this->listingPayload('logistics');

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/listings', $payload)->assertCreated();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/listings', $payload)->assertStatus(429);

        $this->assertDatabaseHas('audit_logs', ['action' => 'spam.duplicate_submission']);
    }

    /** Whitespace and case changes must not defeat the check. */
    public function test_trivial_reformatting_does_not_defeat_duplicate_detection(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/listings', $this->listingPayload('events', [
            'title' => 'Corporate conference management',
            'description' => 'Venue sourcing and on-site crew.',
        ]))->assertCreated();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/listings', $this->listingPayload('events', [
            'title' => '  CORPORATE   Conference    Management ',
            'description' => 'VENUE sourcing and on-site crew.',
        ]))->assertStatus(429);
    }

    /** Two different users posting the same text are not each other's duplicates. */
    public function test_duplicate_detection_is_scoped_per_user(): void
    {
        $payload = $this->listingPayload('construction', [
            'title' => 'Commercial interior fit-out services',
            'description' => 'Turnkey execution with documented handover.',
        ]);

        foreach ([1, 2] as $i) {
            $owner = User::factory()->create(['role' => UserRole::Owner]);
            $this->actingAs($owner, 'sanctum')->postJson('/api/v1/listings', $payload)->assertCreated();
        }

        $this->assertDatabaseCount('listings', 2);
    }

    // --------------------------------------------------------- content analysis

    public function test_markup_and_script_payloads_are_detected(): void
    {
        foreach ([
            '<script>alert(1)</script>',
            '<iframe src="http://evil.example"></iframe>',
            'Click <a href="javascript:alert(1)">here</a>',
            '<img src=x onerror=alert(1)>',
            'Hello <?php system("ls"); ?>',
            'Rendered {{ config.app_key }} here',
        ] as $payload) {
            $this->assertTrue(
                SuspiciousContent::isSuspicious($payload),
                'Expected markup to be flagged: '.$payload
            );
        }
    }

    public function test_link_spam_and_shouting_are_detected(): void
    {
        $this->assertContains('excessive_links', SuspiciousContent::reasons(
            'buy http://a.example http://b.example http://c.example http://d.example http://e.example'
        ));

        $this->assertContains('repeated_characters', SuspiciousContent::reasons('BUYYYYYYYYY NOW'));

        $this->assertContains('excessive_capitals', SuspiciousContent::reasons(
            'URGENT REQUIREMENT PLEASE CONTACT IMMEDIATELY FOR BEST RATES TODAY'
        ));
    }

    /**
     * The false-positive guard, and the most important test here.
     *
     * A real requirement is terse, contains a link, quotes a standard and shouts a
     * little. A filter that rejects this costs a customer, which is worse than the
     * spam it prevents.
     */
    public function test_a_realistic_technical_requirement_is_not_flagged(): void
    {
        foreach ([
            'Need 200 M12x40 bolts IS-1367 class 8.8, drawing at https://drive.example/xyz — GST invoice required, ASAP',
            'Require AC servicing for 12 units at our Noida office. AMC preferred.',
            'Looking for CCTV installation — 8 cameras, NVR, cabling. Budget ₹1,20,000.',
            'Need a website: 5 pages, contact form, hosting. Reference: https://example.com',
            'Urgent: 10 tons cargo from Surat to Bhiwandi by 30th. GST registered transporters only.',
        ] as $text) {
            $this->assertSame(
                [],
                SuspiciousContent::reasons($text),
                'False positive on a legitimate requirement: '.$text
            );
        }
    }

    public function test_thin_submissions_are_identified_without_rejecting_terse_ones(): void
    {
        $this->assertTrue(SuspiciousContent::isTooThin('...'));
        $this->assertTrue(SuspiciousContent::isTooThin('need'));
        $this->assertTrue(SuspiciousContent::isTooThin('a a a'));

        $this->assertFalse(SuspiciousContent::isTooThin('Need AC repair in Noida'));
    }

    // ---------------------------------------------------------------- logging

    /** An operator needs the reason, not the payload. */
    public function test_suspicious_activity_is_logged_without_storing_the_payload(): void
    {
        $marker = 'UNIQUE-PAYLOAD-MARKER-9182';

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload([
                'contact_reference' => $marker,
            ]))
            ->assertStatus(422);

        $log = AuditLog::query()->where('action', 'spam.honeypot_filled')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->customer->id, $log->user_id);
        $this->assertNotNull($log->ip_address);

        // The offending text must not be anywhere in the row — an audit log full of
        // attacker-controlled strings is stored XSS aimed at the admin panel.
        $this->assertStringNotContainsString($marker, json_encode($log->metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($marker, (string) $log->entity_reference);
    }

    /** The audit trail must stay append-only even for these entries. */
    public function test_a_spam_log_entry_cannot_be_altered(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', $this->requirementPayload(['contact_reference' => 'x']))
            ->assertStatus(422);

        $log = AuditLog::query()->where('action', 'spam.honeypot_filled')->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $log->update(['action' => 'spam.tampered']);
    }
}
