<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a buyer may learn about a supplier's verification, and what they may not.
 *
 * The per-check verification map was always returned by BusinessResource but was
 * rendered nowhere except the owner's own settings page. Surfacing it publicly means
 * the boundary between "useful evidence" and "sensitive identifier" now matters, so
 * it is pinned here.
 */
class VerificationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function business(array $overrides = []): Business
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $business = Business::factory()->create(array_merge(['user_id' => $owner->id], $overrides));

        // Verification timestamps are not fillable — an admin action sets them.
        $business->forceFill([
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'business_verified_at' => now(),
            'address_verified_at' => null,
            'gst_verified_at' => null,
        ])->save();

        return $business;
    }

    public function test_a_guest_sees_each_verification_check_individually(): void
    {
        $business = $this->business();

        $verification = $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->json('data.attributes.verification');

        $this->assertTrue($verification['email']);
        $this->assertTrue($verification['phone']);
        $this->assertTrue($verification['business']);
        // Genuinely not done — must be reported as such, not rounded up.
        $this->assertFalse($verification['address']);
    }

    /**
     * The flag that makes the public checklist honest.
     *
     * Without it, `gst === false` cannot be told apart from "this business is below
     * the GST threshold", and the frontend either hides a confirmed check or accuses
     * a compliant supplier of an outstanding one.
     */
    public function test_gst_applicable_is_public_but_the_gst_number_is_not(): void
    {
        $business = $this->business(['gst_number' => '27AAPFU0939F1ZV']);

        $attributes = $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->json('data.attributes');

        $this->assertTrue($attributes['verification']['gst_applicable']);
        $this->assertFalse($attributes['verification']['gst']);

        // The identifier itself must never reach a guest.
        $this->assertArrayNotHasKey('gst_number', $attributes);
    }

    public function test_gst_applicable_is_false_when_no_number_is_held(): void
    {
        $business = $this->business();

        $this->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.attributes.verification.gst_applicable', false);
    }

    /** The owner still gets the number, because they supplied it. */
    public function test_the_owner_can_still_see_their_own_gst_number(): void
    {
        $business = $this->business(['gst_number' => '27AAPFU0939F1ZV']);

        $this->actingAs($business->user, 'sanctum')
            ->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.attributes.gst_number', '27AAPFU0939F1ZV');
    }

    public function test_another_customer_cannot_see_the_gst_number(): void
    {
        $business = $this->business(['gst_number' => '27AAPFU0939F1ZV']);
        $stranger = User::factory()->create(['role' => UserRole::Customer]);

        $attributes = $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/businesses/{$business->slug}")
            ->assertOk()
            ->json('data.attributes');

        $this->assertArrayNotHasKey('gst_number', $attributes);
        // But they can still see that GST applies, which is what they need.
        $this->assertTrue($attributes['verification']['gst_applicable']);
    }

    /**
     * The passport carries the business relationship, which is where the frontend
     * checklist reads the per-check map from. If that relationship stopped being
     * included the checklist would silently render nothing.
     */
    public function test_the_passport_exposes_the_verification_map_through_its_business_relationship(): void
    {
        $business = $this->business(['gst_number' => '27AAPFU0939F1ZV']);

        $verification = $this->getJson("/api/v1/businesses/{$business->slug}/passport")
            ->assertOk()
            ->json('data.relationships.business.attributes.verification');

        $this->assertIsArray($verification);
        $this->assertArrayHasKey('gst_applicable', $verification);
        $this->assertTrue($verification['business']);
    }
}
