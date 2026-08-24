<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'slug' => 'real_estate',
            'name' => 'Real Estate',
            'is_active' => true,
        ]);
    }

    /** Public customer signup always creates a Customer, regardless of what's in the payload. */
    public function test_public_register_always_creates_customer(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.attributes.role', 'customer');
        $this->assertDatabaseHas('users', ['email' => 'priya@example.com', 'role' => 'customer']);
    }

    /** Security test (spec #42): a role in the register payload must never create an Admin. */
    public function test_register_ignores_role_field_and_never_creates_admin(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Hacker',
            'email' => 'hacker@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.attributes.role', 'customer');
        $this->assertDatabaseMissing('users', ['email' => 'hacker@example.com', 'role' => 'admin']);
    }

    /** Owner signup creates the account as Owner (never trusting a supplied role) and a starter business profile. */
    public function test_owner_registration_creates_owner_with_business_profile(): void
    {
        $response = $this->postJson('/api/v1/auth/register/owner', [
            'name' => 'Ramesh Gupta',
            'email' => 'ramesh@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Gupta Properties',
            'city' => 'Ghaziabad',
            'state' => 'Uttar Pradesh',
            'role' => 'admin', // must be ignored
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.attributes.role', 'owner');
        $this->assertDatabaseHas('users', ['email' => 'ramesh@example.com', 'role' => 'owner']);
        $this->assertDatabaseHas('businesses', ['name' => 'Gupta Properties']);
    }

    /** Customer cannot create a listing — Owner-only action, enforced server-side. */
    public function test_customer_cannot_create_listing(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'sanctum');

        $response = $this->postJson('/api/v1/listings', ['category_slug' => 'real_estate']);

        $response->assertForbidden();
    }

    /** Customer cannot reach the admin moderation queue. */
    public function test_customer_cannot_access_admin_listings(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'sanctum');

        $response = $this->getJson('/api/v1/admin/listings');

        $response->assertForbidden();
    }

    /** Owner (not Admin) cannot reach the admin moderation queue either. */
    public function test_owner_cannot_access_admin_listings(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson('/api/v1/admin/listings');

        $response->assertForbidden();
    }

    /** Owner cannot approve their own listing directly — only the admin moderation endpoint can publish it. */
    public function test_owner_cannot_approve_own_listing(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listing = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::PendingReview,
            'submitted_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum');
        $response = $this->postJson("/api/v1/admin/listings/{$listing->uuid}/approve");

        $response->assertForbidden();
    }

    /** Customer cannot access the Owner's business-management endpoint. */
    public function test_customer_cannot_manage_business_profile(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'sanctum');

        $response = $this->postJson('/api/v1/businesses', ['name' => 'Should Not Work']);

        $response->assertForbidden();
    }

    /** Customer cannot view the Owner's "my listings" management endpoint. */
    public function test_customer_cannot_view_owner_listings_endpoint(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'sanctum');

        $response = $this->getJson('/api/v1/users/me/listings');

        $response->assertForbidden();
    }

    /** Customer cannot view the Owner's leads inbox. */
    public function test_customer_cannot_view_leads_inbox(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'sanctum');

        $response = $this->getJson('/api/v1/users/me/leads');

        $response->assertForbidden();
    }

    /** Customer can browse, favorite, and use their own profile without any role friction. */
    public function test_customer_can_favorite_a_published_listing(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listing = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::Published,
            'published_at' => now(),
        ]);

        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'sanctum');

        $response = $this->postJson("/api/v1/listings/{$listing->uuid}/favorite");

        $response->assertCreated();
        $this->assertDatabaseHas('favorites', ['user_id' => $customer->id, 'listing_id' => $listing->id]);
    }

    /** Wrong password returns a neutral message that doesn't reveal whether the email exists. */
    public function test_invalid_login_returns_neutral_error(): void
    {
        User::factory()->create(['role' => UserRole::Customer, 'email' => 'known@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'known@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            'Invalid email or password.',
            $response->json('errors.email.0')
        );
    }

    /** Login email is matched case-insensitively / trimmed, same as registration. */
    public function test_login_normalizes_email_case(): void
    {
        User::factory()->create(['role' => UserRole::Customer, 'email' => 'test@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'Test@Example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
    }

    /** Regression: the register response must include the new user's own email, not silently omit it. */
    public function test_customer_register_response_includes_own_email(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Email Check',
            'email' => 'emailcheck@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.attributes.email', 'emailcheck@example.com');
    }

    /** Same regression for Owner registration. */
    public function test_owner_register_response_includes_own_email(): void
    {
        $response = $this->postJson('/api/v1/auth/register/owner', [
            'name' => 'Owner Email Check',
            'email' => 'ownercheck@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Check Co',
            'city' => 'Pune',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.attributes.email', 'ownercheck@example.com');
    }

    /**
     * Regression: registering with a case/whitespace variant of an email that
     * already exists (once normalized) must be rejected as a clean 422
     * validation error — not bypass the uniqueness check and crash with a
     * raw database exception. Both the trim/lowercase-before-validation fix
     * and the catch-all safe-error-envelope fix are covered by this test.
     */
    public function test_duplicate_email_rejected_even_with_different_case_and_whitespace(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'First',
            'email' => 'dupe@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Second',
            'email' => ' Dupe@Example.com ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Validation failed.');
        $response->assertJsonPath('errors.email.0', 'Email already registered.');
        // Never leak internals even on the failure path.
        $response->assertJsonMissingPath('exception');
        $response->assertJsonMissingPath('trace');

        $this->assertSame(1, User::where('email', 'dupe@example.com')->count());
    }

    /** Popular locations are derived from real published listings, not a hardcoded city list. */
    public function test_popular_locations_reflect_published_listings_across_cities(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        foreach (['Mumbai', 'Ghaziabad', 'Bangalore'] as $city) {
            $location = Location::create(['country' => 'IN', 'city' => $city]);
            Listing::factory()->for($owner)->for($this->category)->create([
                'location_id' => $location->id,
                'status' => ListingStatus::Published,
                'published_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/v1/locations/popular');

        $response->assertOk();
        $cities = collect($response->json('data'))->pluck('city');
        $this->assertTrue($cities->contains('Mumbai'));
        $this->assertTrue($cities->contains('Ghaziabad'));
        $this->assertTrue($cities->contains('Bangalore'));
        $this->assertFalse($cities->contains('Kolkata'));
    }
}
