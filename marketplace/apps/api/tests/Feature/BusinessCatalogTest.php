<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2: Business Profile + Business Catalog. Products/Services are a
 * Business's catalog capabilities, distinct from Listings (see Listing
 * tests in ListingWorkflowTest, unmodified).
 */
class BusinessCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'slug' => 'textiles',
            'name' => 'Textiles',
            'is_active' => true,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }

    // ---------------------------------------------------------------
    // Business profile creation / update
    // ---------------------------------------------------------------

    public function test_owner_creates_business_profile_with_full_details(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/businesses', [
            'name' => 'Ananya Textiles',
            'about' => 'Wholesale cotton fabric supplier.',
            'business_type' => 'manufacturer',
            'years_in_business' => 12,
            'trade_type' => 'b2b',
            'category_slug' => 'textiles',
            'business_hours' => [
                'monday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
                'sunday' => ['closed' => true],
            ],
            'location' => ['city' => 'Surat', 'state' => 'Gujarat'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.name', 'Ananya Textiles');
        $response->assertJsonPath('data.attributes.business_type', 'manufacturer');
        $response->assertJsonPath('data.attributes.trade_type', 'b2b');
        $response->assertJsonPath('data.relationships.category.attributes.slug', 'textiles');
        $this->assertDatabaseHas('businesses', ['name' => 'Ananya Textiles', 'years_in_business' => 12]);
    }

    public function test_owner_updates_existing_business_profile(): void
    {
        $owner = $this->owner();
        $business = Business::factory()->for($owner)->create(['name' => 'Old Name']);

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/businesses', [
            'name' => 'New Name',
            'years_in_business' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.name', 'New Name');
        $this->assertSame(1, Business::where('user_id', $owner->id)->count());
        $this->assertDatabaseHas('businesses', ['id' => $business->id, 'name' => 'New Name']);
    }

    public function test_customer_cannot_create_business_profile(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/businesses', ['name' => 'Should Fail']);

        $response->assertForbidden();
    }

    public function test_business_registration_does_not_hardcode_any_city(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/businesses', [
            'name' => 'Bangalore Electronics Hub',
            'location' => ['city' => 'Bangalore', 'state' => 'Karnataka'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('locations', ['city' => 'Bangalore', 'state' => 'Karnataka']);
        $this->assertDatabaseMissing('locations', ['city' => 'Kolkata']);
    }

    // ---------------------------------------------------------------
    // GST validation / verification (never falsely marked verified)
    // ---------------------------------------------------------------

    public function test_invalid_gst_number_format_is_rejected(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/businesses', [
            'name' => 'Test Biz',
            'gst_number' => 'not-a-valid-gst',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('gst_number');
    }

    public function test_submitting_gst_number_does_not_auto_verify_it(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/businesses', [
            'name' => 'Test Biz',
            'gst_number' => '29ABCDE1234F1Z5',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.verification.gst', false);
        $response->assertJsonPath('data.attributes.is_verified', false);
    }

    public function test_admin_can_verify_a_business_dimension(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = $this->owner();
        $business = Business::factory()->for($owner)->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/businesses/{$business->id}/verify", [
            'type' => 'phone',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.verification.phone', true);
        $this->assertNotNull($business->fresh()->phone_verified_at);
    }

    public function test_owner_cannot_verify_their_own_business(): void
    {
        $owner = $this->owner();
        $business = Business::factory()->for($owner)->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/admin/businesses/{$business->id}/verify", [
            'type' => 'phone',
        ]);

        $response->assertForbidden();
    }

    public function test_is_verified_only_becomes_true_once_every_dimension_is_actually_verified(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = $this->owner();
        $business = Business::factory()->for($owner)->create(['gst_number' => null]);

        foreach (['email', 'phone', 'business'] as $type) {
            $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/businesses/{$business->id}/verify", ['type' => $type]);
            $this->assertFalse($business->fresh()->is_verified, "should not be verified after only {$type}");
        }

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/businesses/{$business->id}/verify", ['type' => 'address']);

        $this->assertTrue($business->fresh()->is_verified);
    }

    // ---------------------------------------------------------------
    // Service areas
    // ---------------------------------------------------------------

    public function test_owner_can_set_service_areas_across_multiple_cities(): void
    {
        $owner = $this->owner();
        Business::factory()->for($owner)->create();

        $response = $this->actingAs($owner, 'sanctum')->putJson('/api/v1/businesses/service-areas', [
            'locations' => [
                ['city' => 'Pune', 'state' => 'Maharashtra'],
                ['city' => 'Nagpur', 'state' => 'Maharashtra'],
                ['city' => 'Indore', 'state' => 'Madhya Pradesh'],
            ],
        ]);

        $response->assertOk();
        $this->assertCount(3, $response->json('data.relationships.service_areas'));
    }

    public function test_service_areas_sync_replaces_previous_set(): void
    {
        $owner = $this->owner();
        $business = Business::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/businesses/service-areas', [
            'locations' => [['city' => 'Pune']],
        ]);
        $response = $this->actingAs($owner, 'sanctum')->putJson('/api/v1/businesses/service-areas', [
            'locations' => [['city' => 'Chennai']],
        ]);

        $response->assertOk();
        $this->assertSame(1, $business->serviceAreas()->count());
        $this->assertSame('Chennai', $business->serviceAreas()->first()->city);
    }

    public function test_customer_cannot_set_service_areas(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $response = $this->actingAs($customer, 'sanctum')->putJson('/api/v1/businesses/service-areas', [
            'locations' => [['city' => 'Pune']],
        ]);

        $response->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Industries served (capability management)
    // ---------------------------------------------------------------

    public function test_owner_can_assign_industries_to_their_business(): void
    {
        $owner = $this->owner();
        Business::factory()->for($owner)->create();
        $industries = Industry::factory()->count(3)->create();

        $response = $this->actingAs($owner, 'sanctum')->putJson('/api/v1/businesses/industries', [
            'industry_ids' => $industries->pluck('id')->all(),
        ]);

        $response->assertOk();
        $this->assertCount(3, $response->json('data.relationships.industries'));
    }

    // ---------------------------------------------------------------
    // Products (capability management)
    // ---------------------------------------------------------------

    public function test_owner_creates_a_product(): void
    {
        $owner = $this->owner();
        Business::factory()->for($owner)->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/products', [
            'category_slug' => 'textiles',
            'name' => 'Cotton Fabric Rolls',
            'unit' => 'roll',
            'min_order' => 10,
            'max_order' => 1000,
            'customization_available' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.name', 'Cotton Fabric Rolls');
        $this->assertDatabaseHas('products', ['name' => 'Cotton Fabric Rolls', 'min_order' => 10]);
    }

    public function test_customer_cannot_create_a_product(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/products', ['name' => 'Should Fail']);

        $response->assertForbidden();
    }

    public function test_owner_edits_their_own_product(): void
    {
        $owner = $this->owner();
        $business = Business::factory()->for($owner)->create();
        $product = Product::factory()->for($business)->create(['name' => 'Old Product Name']);

        $response = $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/products/{$product->id}", [
            'name' => 'Updated Product Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attributes.name', 'Updated Product Name');
    }

    public function test_business_a_cannot_edit_business_bs_product(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $businessB = Business::factory()->for($ownerB)->create();
        $product = Product::factory()->for($businessB)->create();

        $response = $this->actingAs($ownerA, 'sanctum')->patchJson("/api/v1/products/{$product->id}", [
            'name' => 'Hijacked',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('products', ['id' => $product->id, 'name' => 'Hijacked']);
    }

    public function test_business_a_cannot_delete_business_bs_product(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $businessB = Business::factory()->for($ownerB)->create();
        $product = Product::factory()->for($businessB)->create();

        $response = $this->actingAs($ownerA, 'sanctum')->deleteJson("/api/v1/products/{$product->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_public_can_view_a_businesss_active_products(): void
    {
        $owner = $this->owner();
        $business = Business::factory()->for($owner)->create(['slug' => 'ananya-textiles']);
        Product::factory()->for($business)->create(['name' => 'Visible Product', 'is_active' => true]);
        Product::factory()->for($business)->create(['name' => 'Hidden Draft Product', 'is_active' => false]);

        $response = $this->getJson('/api/v1/businesses/ananya-textiles/products');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('attributes.name');
        $this->assertTrue($names->contains('Visible Product'));
        $this->assertFalse($names->contains('Hidden Draft Product'));
    }

    // ---------------------------------------------------------------
    // Services (capability management)
    // ---------------------------------------------------------------

    public function test_owner_creates_a_service(): void
    {
        $owner = $this->owner();
        Business::factory()->for($owner)->create();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/services', [
            'name' => 'Custom Dyeing',
            'estimated_delivery_time' => '5-7 days',
            'customization_available' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('services', ['name' => 'Custom Dyeing']);
    }

    public function test_business_a_cannot_edit_business_bs_service(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $businessB = Business::factory()->for($ownerB)->create();
        $service = Service::factory()->for($businessB)->create();

        $response = $this->actingAs($ownerA, 'sanctum')->patchJson("/api/v1/services/{$service->id}", [
            'name' => 'Hijacked',
        ]);

        $response->assertForbidden();
    }

    public function test_owner_deletes_their_own_service(): void
    {
        $owner = $this->owner();
        $business = Business::factory()->for($owner)->create();
        $service = Service::factory()->for($business)->create();

        $response = $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/services/{$service->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    // ---------------------------------------------------------------
    // Public business profile — only public information is exposed
    // ---------------------------------------------------------------

    public function test_public_business_profile_hides_gst_number(): void
    {
        $owner = $this->owner();
        Business::factory()->for($owner)->create([
            'slug' => 'private-biz',
            'gst_number' => '29ABCDE1234F1Z5',
        ]);

        $response = $this->getJson('/api/v1/businesses/private-biz');

        $response->assertOk();
        $this->assertArrayNotHasKey('gst_number', $response->json('data.attributes'));
    }

    public function test_owner_can_see_their_own_gst_number(): void
    {
        $owner = $this->owner();
        Business::factory()->for($owner)->create([
            'slug' => 'my-biz',
            'gst_number' => '29ABCDE1234F1Z5',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/users/me/business');

        $response->assertOk();
        $response->assertJsonPath('data.attributes.gst_number', '29ABCDE1234F1Z5');
    }

    public function test_public_business_profile_shows_verification_as_booleans_only(): void
    {
        $owner = $this->owner();
        Business::factory()->for($owner)->create(['slug' => 'verified-biz']);

        $response = $this->getJson('/api/v1/businesses/verified-biz');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['attributes' => ['verification' => ['email', 'phone', 'business', 'address', 'gst']]]]);
    }

    // ---------------------------------------------------------------
    // Listing catalog fields (kept — existing listing functionality)
    // ---------------------------------------------------------------

    public function test_listing_supports_price_range_unit_and_min_order(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/listings', [
            'category_slug' => 'textiles',
            'title' => 'Cotton Fabric',
            'price' => 100,
            'price_max' => 150,
            'unit' => 'meter',
            'min_order_quantity' => 50,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.attributes.price_max', '150.00');
        $response->assertJsonPath('data.attributes.unit', 'meter');
        $response->assertJsonPath('data.attributes.min_order_quantity', 50);
    }
}
