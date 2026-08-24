<?php

namespace Tests\Feature;

use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequirementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'slug' => 'packaging',
            'name' => 'Packaging Materials',
            'is_active' => true,
        ]);
    }

    private function actingAsCustomer(): User
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'sanctum');

        return $customer;
    }

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    private function draftPayload(): array
    {
        return [
            'category_id' => $this->category->id,
            'title' => 'Need 500 corrugated boxes',
            'description' => 'Standard 12x12x12 boxes, bulk order',
            'quantity' => 500,
            'unit' => 'pieces',
            'budget_min' => 20000,
            'budget_max' => 35000,
            'required_date' => now()->addDays(14)->toDateString(),
            'urgency' => 'high',
            'location' => ['city' => 'Ahmedabad', 'state' => 'Gujarat', 'country' => 'IN', 'area' => 'Bapunagar'],
        ];
    }

    /** 1. Customer creates a draft requirement. */
    public function test_customer_creates_draft(): void
    {
        $this->actingAsCustomer();

        $response = $this->postJson('/api/v1/requirements', $this->draftPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.status', 'draft');
        $response->assertJsonPath('data.relationships.location.attributes.city', 'Ahmedabad');
        $this->assertDatabaseHas('requirements', ['status' => 'draft', 'title' => 'Need 500 corrugated boxes']);
    }

    /** A draft can be started with just a category — everything else is optional at this stage. */
    public function test_customer_creates_minimal_draft_with_only_category(): void
    {
        $this->actingAsCustomer();

        $response = $this->postJson('/api/v1/requirements', ['category_id' => $this->category->id]);

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.status', 'draft');
        $response->assertJsonPath('data.attributes.title', null);
    }

    /** 2. Validation: category_id is the one thing that's actually required to start a draft. */
    public function test_creating_requirement_without_category_fails_validation(): void
    {
        $this->actingAsCustomer();

        $response = $this->postJson('/api/v1/requirements', ['title' => 'No category']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category_id']);
    }

    /** Validation: budget_max must not be less than budget_min. */
    public function test_budget_max_must_be_greater_than_or_equal_to_budget_min(): void
    {
        $this->actingAsCustomer();

        $payload = $this->draftPayload();
        $payload['budget_min'] = 50000;
        $payload['budget_max'] = 10000;

        $response = $this->postJson('/api/v1/requirements', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['budget_max']);
    }

    /** Only Customer accounts may raise a requirement — an Owner (business) account cannot. */
    public function test_owner_cannot_create_requirement(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/requirements', $this->draftPayload());

        $response->assertForbidden();
    }

    /** 3. Customer updates their own draft. */
    public function test_customer_updates_draft(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', $this->draftPayload())->json('data.id');

        $response = $this->patchJson("/api/v1/requirements/{$id}", ['title' => 'Updated requirement title']);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.title', 'Updated requirement title');
    }

    /** Regression: explicitly clearing description (sending null) must actually clear it. */
    public function test_customer_can_clear_description_on_draft(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', $this->draftPayload())->json('data.id');

        $response = $this->patchJson("/api/v1/requirements/{$id}", ['description' => null]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.description', null);
    }

    /** 4. Customer publishes a complete draft. */
    public function test_customer_publishes_requirement(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', $this->draftPayload())->json('data.id');

        $response = $this->postJson("/api/v1/requirements/{$id}/publish");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', 'published');
        $this->assertDatabaseHas('requirements', ['uuid' => $id, 'status' => 'published']);
    }

    /** Publishing an incomplete draft (missing quantity/location/date) is rejected. */
    public function test_publish_fails_when_requirement_is_incomplete(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', [
            'category_id' => $this->category->id,
            'title' => 'Just a title',
        ])->json('data.id');

        $response = $this->postJson("/api/v1/requirements/{$id}/publish");

        $response->assertStatus(422);
        $this->assertDatabaseHas('requirements', ['uuid' => $id, 'status' => 'draft']);
    }

    /** Once published, a requirement is no longer editable via the draft-update endpoint. */
    public function test_cannot_edit_requirement_after_publishing(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', $this->draftPayload())->json('data.id');
        $this->postJson("/api/v1/requirements/{$id}/publish")->assertOk();

        $response = $this->patchJson("/api/v1/requirements/{$id}", ['title' => 'Too late']);

        $response->assertForbidden();
    }

    /** 5. Customer cancels a published requirement. */
    public function test_customer_cancels_requirement(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', $this->draftPayload())->json('data.id');
        $this->postJson("/api/v1/requirements/{$id}/publish")->assertOk();

        $response = $this->postJson("/api/v1/requirements/{$id}/cancel");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', 'cancelled');
    }

    /** A customer can also cancel straight from draft. */
    public function test_customer_cancels_draft_requirement(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', $this->draftPayload())->json('data.id');

        $response = $this->postJson("/api/v1/requirements/{$id}/cancel");

        $response->assertOk();
        $response->assertJsonPath('data.attributes.status', 'cancelled');
    }

    /** A closed requirement can no longer be cancelled. */
    public function test_cannot_cancel_a_closed_requirement(): void
    {
        $customer = $this->actingAsCustomer();
        $requirement = Requirement::factory()->for($customer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Closed,
            'closed_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/requirements/{$requirement->uuid}/cancel");

        $response->assertForbidden();
    }

    /** 6. Customer can view their own requirement, including a draft. */
    public function test_customer_can_view_own_requirement(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', $this->draftPayload())->json('data.id');

        $response = $this->getJson("/api/v1/requirements/{$id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $id);
    }

    /**
     * Customer can read the matches for their own requirement. A draft has
     * never been offered to anyone, so the list is legitimately empty —
     * matching itself is covered in RequirementMatchingTest.
     */
    public function test_customer_can_view_matches_for_own_requirement(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/requirements', $this->draftPayload())->json('data.id');

        $response = $this->getJson("/api/v1/requirements/{$id}/matches");

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    /** 7. Unauthorized access: an unauthenticated visitor cannot access requirement endpoints. */
    public function test_guest_cannot_access_requirement_endpoints(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = Requirement::factory()->for($customer, 'customer')->for($this->category)->create();

        $response = $this->getJson("/api/v1/requirements/{$requirement->uuid}");

        $response->assertUnauthorized();
    }

    /** 8. Cross-user protection: Customer A cannot view Customer B's private draft requirement. */
    public function test_customer_cannot_view_another_customers_draft(): void
    {
        $ownerCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = Requirement::factory()->for($ownerCustomer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Draft,
        ]);

        $this->actingAsCustomer();
        $response = $this->getJson("/api/v1/requirements/{$requirement->uuid}");

        $response->assertForbidden();
    }

    /** Cross-user protection: Customer A cannot modify Customer B's requirement. */
    public function test_customer_cannot_update_another_customers_requirement(): void
    {
        $ownerCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = Requirement::factory()->for($ownerCustomer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Draft,
        ]);

        $this->actingAsCustomer();
        $response = $this->patchJson("/api/v1/requirements/{$requirement->uuid}", ['title' => 'Hijacked']);

        $response->assertForbidden();
    }

    /** Cross-user protection: Customer A cannot cancel Customer B's requirement. */
    public function test_customer_cannot_cancel_another_customers_requirement(): void
    {
        $ownerCustomer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = Requirement::factory()->for($ownerCustomer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Published,
            'published_at' => now(),
        ]);

        $this->actingAsCustomer();
        $response = $this->postJson("/api/v1/requirements/{$requirement->uuid}/cancel");

        $response->assertForbidden();
    }

    /** A business cannot view a requirement still in draft. */
    public function test_business_cannot_view_draft_requirement(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = Requirement::factory()->for($customer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Draft,
        ]);

        $this->actingAsOwner();
        $response = $this->getJson("/api/v1/requirements/{$requirement->uuid}");

        $response->assertForbidden();
    }

    /** A business can view a published requirement. */
    public function test_business_can_view_published_requirement(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $requirement = Requirement::factory()->for($customer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Published,
            'published_at' => now(),
        ]);

        $this->actingAsOwner();
        $response = $this->getJson("/api/v1/requirements/{$requirement->uuid}");

        $response->assertOk();
    }

    /** A business browsing a published requirement never sees which customer raised it. */
    public function test_business_does_not_see_customer_identity_on_published_requirement(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Private Customer']);
        $requirement = Requirement::factory()->for($customer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Published,
            'published_at' => now(),
        ]);

        $this->actingAsOwner();
        $response = $this->getJson("/api/v1/requirements/{$requirement->uuid}");

        $response->assertOk();
        $response->assertJsonMissing(['name' => 'Private Customer']);
    }

    /** The business browse endpoint only ever returns publicly-visible requirements. */
    public function test_business_browse_only_shows_publicly_visible_requirements(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $draft = Requirement::factory()->for($customer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Draft,
        ]);
        $published = Requirement::factory()->for($customer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Published,
            'published_at' => now(),
        ]);
        $cancelled = Requirement::factory()->for($customer, 'customer')->for($this->category)->create([
            'status' => RequirementStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->actingAsOwner();
        $response = $this->getJson('/api/v1/requirements');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $published->uuid]);
        $response->assertJsonMissing(['id' => $draft->uuid]);
        $response->assertJsonMissing(['id' => $cancelled->uuid]);
    }

    /** A customer's own requirement listing never leaks another customer's requirements. */
    public function test_customer_index_only_shows_own_requirements(): void
    {
        $customer = $this->actingAsCustomer();
        $other = User::factory()->create(['role' => UserRole::Customer]);

        Requirement::factory()->for($customer, 'customer')->for($this->category)->create(['title' => 'Mine']);
        Requirement::factory()->for($other, 'customer')->for($this->category)->create(['title' => 'Not mine']);

        $response = $this->getJson('/api/v1/users/me/requirements');

        $response->assertOk();
        $response->assertJsonFragment(['title' => 'Mine']);
        $response->assertJsonMissing(['title' => 'Not mine']);
        $response->assertJsonPath('meta.status_counts.draft', 1);
    }

    /** A Customer account cannot use the business-only public browse endpoint. */
    public function test_customer_cannot_use_business_browse_endpoint(): void
    {
        $this->actingAsCustomer();

        $response = $this->getJson('/api/v1/requirements');

        $response->assertForbidden();
    }
}
