<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWorkflowTest extends TestCase
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

    private function publishedListing(?User $owner = null): Listing
    {
        $owner ??= User::factory()->create(['role' => UserRole::Owner]);

        return Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::Published,
            'published_at' => now(),
        ]);
    }

    /** A guest (no auth) can submit an enquiry on a published listing. */
    public function test_guest_can_submit_lead_for_published_listing(): void
    {
        $listing = $this->publishedListing();

        $response = $this->postJson("/api/v1/listings/{$listing->uuid}/leads", [
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'message' => 'Is this still available?',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.status', 'new');
        $this->assertDatabaseHas('leads', ['listing_id' => $listing->id, 'name' => 'Priya Sharma']);
    }

    /** A lead needs at least one contact method — name alone isn't enough. */
    public function test_lead_requires_email_or_phone(): void
    {
        $listing = $this->publishedListing();

        $response = $this->postJson("/api/v1/listings/{$listing->uuid}/leads", ['name' => 'No Contact']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /** Enquiries on a draft/unpublished listing are rejected. */
    public function test_cannot_submit_lead_for_unpublished_listing(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listing = Listing::factory()->for($owner)->for($this->category)->create(['status' => ListingStatus::Draft]);

        $response = $this->postJson("/api/v1/listings/{$listing->uuid}/leads", [
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422);
    }

    /** Owners only see leads for listings they own. */
    public function test_owner_sees_only_their_own_leads(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $otherOwner = User::factory()->create(['role' => UserRole::Owner]);

        $ownListing = $this->publishedListing($owner);
        $otherListing = $this->publishedListing($otherOwner);

        Lead::factory()->for($ownListing, 'listing')->create(['name' => 'My Lead']);
        Lead::factory()->for($otherListing, 'listing')->create(['name' => 'Not Mine']);

        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/v1/users/me/leads');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'My Lead']);
        $response->assertJsonMissing(['name' => 'Not Mine']);
        $response->assertJsonPath('meta.status_counts.new', 1);
    }

    /** Owner can move a lead to "contacted" and contacted_at gets stamped. */
    public function test_owner_can_update_lead_status(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listing = $this->publishedListing($owner);
        $lead = Lead::factory()->for($listing, 'listing')->create();

        $this->actingAs($owner, 'sanctum');
        $response = $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted']);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', 'contacted');
        $this->assertNotNull($lead->fresh()->contacted_at);
    }

    /** A stranger cannot update someone else's lead. */
    public function test_stranger_cannot_update_another_owners_lead(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listing = $this->publishedListing($owner);
        $lead = Lead::factory()->for($listing, 'listing')->create();

        $stranger = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($stranger, 'sanctum');

        $response = $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted']);

        $response->assertForbidden();
    }
}
