<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\CategoryAttributeDefinition;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingWorkflowTest extends TestCase
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

        CategoryAttributeDefinition::create([
            'category_id' => $this->category->id,
            'key' => 'property_type',
            'label' => 'Property Type',
            'type' => 'select',
            'options' => ['apartment', 'villa'],
            'is_required' => true,
            'is_filterable' => true,
        ]);
    }

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function draftPayload(): array
    {
        return [
            'category_slug' => 'real_estate',
            'title' => 'Cozy 2BHK in New Town',
            'description' => 'Nice place',
            'price' => 4500000,
            'currency' => 'INR',
            'location' => ['city' => 'Kolkata', 'state' => 'West Bengal', 'country' => 'IN', 'area' => 'New Town'],
            'attributes' => ['property_type' => 'apartment'],
        ];
    }

    /** 1. Owner creates draft */
    public function test_owner_creates_draft(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/listings', $this->draftPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.system_status', 'draft');
        $this->assertDatabaseHas('listings', ['status' => 'draft']);
    }

    /** 2. Owner updates draft */
    public function test_owner_updates_draft(): void
    {
        $this->actingAsOwner();
        $listingId = $this->postJson('/api/v1/listings', $this->draftPayload())->json('data.id');

        $response = $this->patchJson("/api/v1/listings/{$listingId}", ['title' => 'Updated title']);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.title', 'Updated title');
    }

    /** Regression: a draft with just a category and no title/price must not crash (title/price are nullable). */
    public function test_owner_creates_minimal_draft_with_only_category(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/listings', ['category_slug' => 'real_estate']);

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.system_status', 'draft');
        $response->assertJsonPath('data.attributes.title', null);
    }

    /** Regression: explicitly clearing description (sending null) must actually clear it, not silently keep the old value. */
    public function test_owner_can_clear_description_on_draft(): void
    {
        $this->actingAsOwner();
        $listingId = $this->postJson('/api/v1/listings', $this->draftPayload())->json('data.id');

        $response = $this->patchJson("/api/v1/listings/{$listingId}", ['description' => null]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.description', null);
    }

    /** 3 & 4. Owner submits listing -> becomes pending review */
    public function test_owner_submits_listing_for_review(): void
    {
        $this->actingAsOwner();
        $listingId = $this->postJson('/api/v1/listings', $this->draftPayload())->json('data.id');

        $response = $this->postJson("/api/v1/listings/{$listingId}/submit");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.system_status', 'pending_review');
        $this->assertDatabaseHas('listings', ['status' => 'pending_review']);
    }

    /** 5. Owner cannot publish directly */
    public function test_owner_cannot_publish_directly(): void
    {
        $this->actingAsOwner();
        $listingId = $this->postJson('/api/v1/listings', $this->draftPayload())->json('data.id');

        // Owner has no publish/approve endpoint at all — attempting the admin route is forbidden.
        $response = $this->postJson("/api/v1/admin/listings/{$listingId}/approve");

        $response->assertForbidden();
    }

    /** 6. Admin sees pending listing */
    public function test_admin_sees_pending_listings(): void
    {
        $owner = $this->actingAsOwner();
        $listing = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::PendingReview,
            'submitted_at' => now(),
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/listings');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $listing->uuid]);
    }

    /** 7 & 8. Admin approves -> listing becomes published */
    public function test_admin_approves_listing(): void
    {
        $owner = $this->actingAsOwner();
        $listing = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::PendingReview,
            'submitted_at' => now(),
        ]);

        $this->actingAsAdmin();
        $response = $this->postJson("/api/v1/admin/listings/{$listing->uuid}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.system_status', 'published');
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'published']);
    }

    /** 9 & 10. Admin rejects -> owner sees rejection reason */
    public function test_admin_rejects_listing_and_owner_sees_reason(): void
    {
        $owner = $this->actingAsOwner();
        $listing = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::PendingReview,
            'submitted_at' => now(),
        ]);

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/listings/{$listing->uuid}/reject", ['reason' => 'Photos are unclear'])
            ->assertOk()
            ->assertJsonPath('data.attributes.system_status', 'rejected');

        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson("/api/v1/listings/{$listing->uuid}");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.rejection_reason', 'Photos are unclear');
    }

    /** 11. Owner can update rejected listing */
    public function test_owner_can_update_rejected_listing(): void
    {
        $owner = $this->actingAsOwner();
        $listing = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::Rejected,
            'rejection_reason' => 'Needs better title',
        ]);

        $response = $this->patchJson("/api/v1/listings/{$listing->uuid}", ['title' => 'Much better title']);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.system_status', 'draft');
        $response->assertJsonPath('data.attributes.title', 'Much better title');
    }

    /** 12. Customer cannot manage another user's (Owner's) listing */
    public function test_user_cannot_manage_another_users_listing(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listing = Listing::factory()->for($owner)->for($this->category)->create(['status' => ListingStatus::Draft]);

        $stranger = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($stranger, 'sanctum');

        $response = $this->patchJson("/api/v1/listings/{$listing->uuid}", ['title' => 'Hijacked']);

        $response->assertForbidden();
    }

    /** 13. Sold listing has correct availability while remaining published */
    public function test_owner_can_mark_published_listing_sold(): void
    {
        $owner = $this->actingAsOwner();
        $listing = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::Published,
            'published_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/listings/{$listing->uuid}/mark-sold");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.system_status', 'published');
        $response->assertJsonPath('data.attributes.availability_status', 'sold');
    }

    /** 14. Archived listing doesn't appear in public listing */
    public function test_archived_listing_not_publicly_visible(): void
    {
        $owner = $this->actingAsOwner();
        $listing = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::Archived,
            'archived_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/listings');

        $response->assertOk();
        $response->assertJsonMissing(['id' => $listing->uuid]);
    }

    /** Regression: filter[q] must actually keyword-search title/description, not silently filter by category. */
    public function test_public_listings_can_be_searched_by_keyword(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $match = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::Published,
            'published_at' => now(),
            'title' => 'Spacious 3 BHK Apartment',
        ]);
        $noMatch = Listing::factory()->for($owner)->for($this->category)->create([
            'status' => ListingStatus::Published,
            'published_at' => now(),
            'title' => 'Commercial Office Space',
        ]);

        $response = $this->getJson('/api/v1/listings?filter[q]=3+BHK');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $match->uuid]);
        $response->assertJsonMissing(['id' => $noMatch->uuid]);
    }
}
