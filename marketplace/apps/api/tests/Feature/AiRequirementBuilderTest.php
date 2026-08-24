<?php

namespace Tests\Feature;

use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Exceptions\Ai\AiUnavailableException;
use App\Models\AiAction;
use App\Models\Business;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Requirement;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * Phase 12 — requirement builder and natural-language search.
 *
 * The theme running through these is that the model's output is a *hint*, never data.
 * Every test either checks that an invented value was rejected, or that a deterministic
 * parser overrode a wrong one, or that nothing was written without confirmation.
 */
class AiRequirementBuilderTest extends TestCase
{
    use InteractsWithAi, RefreshDatabase;

    private User $customer;

    private User $owner;

    private Category $construction;

    private Category $itSoftware;

    private Location $noida;

    protected function setUp(): void
    {
        parent::setUp();

        $this->construction = Category::factory()->create(['name' => 'Construction', 'slug' => 'construction']);
        $this->itSoftware = Category::factory()->create(['name' => 'IT & Software', 'slug' => 'it-software']);
        $this->noida = Location::factory()->create(['city' => 'Noida', 'state' => 'Uttar Pradesh']);

        $this->customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->owner = Business::factory()->create([
            'category_id' => $this->construction->id,
            'location_id' => $this->noida->id,
        ])->user;
    }

    /* ------------------------------------------------------------------ *
     | Extraction
     * ------------------------------------------------------------------ */

    /** The brief's own worked example, end to end. */
    public function test_it_extracts_a_full_requirement_from_plain_english(): void
    {
        $this->fakeAi()->willReplyJson([
            'title' => 'Office interior fit-out',
            'category' => 'construction',
            'city' => 'Noida',
            'quantity' => 3000,
            'unit' => 'sq ft',
            'budget_text' => '15 lakh',
            'budget_max' => 1500000,
            'timeline_text' => '3 months',
            'specifications' => ['3000 sq ft'],
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', [
                'input' => 'I need office interior for around 3000 sq ft in Noida. Budget around 15 lakh. Need it completed in 3 months.',
            ])
            ->assertOk();

        $response->assertJsonPath('data.category', 'construction');
        $response->assertJsonPath('data.category_name', 'Construction');
        $response->assertJsonPath('data.city', 'Noida');
        $response->assertJsonPath('data.quantity', 3000);
        $response->assertJsonPath('data.unit', 'sq ft');
        $response->assertJsonPath('data.budget_max', 1500000);
        $response->assertJsonPath('data.currency', 'INR');
        $response->assertJsonPath('meta.ready_to_draft', true);
        $this->assertNotNull($response->json('data.required_date'));

        // Extraction alone must never write.
        $this->assertDatabaseCount('requirements', 0);
        $response->assertJsonPath('meta.note', 'Nothing has been saved. Review the fields, then create the draft yourself.');
    }

    /**
     * The failure this whole layer exists to prevent.
     *
     * The model returns 15 for "15 lakh". Our own parse of the phrase wins, and
     * confidence drops so the UI flags the field rather than accepting it silently.
     */
    public function test_an_inflated_model_figure_is_caught_even_without_a_quoted_phrase(): void
    {
        $this->fakeAi()->willReplyJson([
            'title' => 'Office interior',
            'category' => 'construction',
            'city' => 'Noida',
            // No budget_text at all — the case that used to slip straight through,
            // found by live testing against a 3B model.
            'budget_max' => 8000000,
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', [
                'input' => 'Mujhe Noida mein office interior chahiye, 2000 sq ft, budget 8 lakh, 2 mahine mein',
            ])
            ->assertOk();

        // 8 lakh, not 80 lakh.
        $response->assertJsonPath('data.budget_max', 800000);
        // Flagged for review, because two sources disagreed by an order of magnitude.
        $this->assertLessThan(0.8, $response->json('meta.confidence.budget'));
    }

    /**
     * A timeline number must not be absorbed into the budget.
     *
     * The keyword window used to be a blind 30-character span, which could swallow the
     * "2" from "2 mahine mein".
     */
    public function test_a_timeline_number_is_not_absorbed_into_the_budget(): void
    {
        $this->fakeAi()->willReplyJson(['title' => 'Fit-out', 'category' => 'construction', 'city' => 'Noida']);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', [
                'input' => 'office interior chahiye, budget 8 lakh, 2 mahine mein',
            ])
            ->assertOk()
            ->assertJsonPath('data.budget_max', 800000);
    }

    public function test_a_model_that_misreads_lakh_is_overridden_by_the_deterministic_parser(): void
    {
        $this->fakeAi()->willReplyJson([
            'title' => 'Warehouse racking',
            'category' => 'construction',
            'city' => 'Noida',
            'budget_text' => '15 lakh',
            // Off by five orders of magnitude.
            'budget_max' => 15,
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'warehouse racking in Noida, budget 15 lakh'])
            ->assertOk();

        $response->assertJsonPath('data.budget_max', 1500000);
        $this->assertLessThan(0.85, $response->json('meta.confidence.budget'));
    }

    public function test_a_budget_is_recovered_from_the_text_when_the_model_misses_it_entirely(): void
    {
        $this->fakeAi()->willReplyJson([
            'title' => 'Steel fabrication',
            'category' => 'construction',
            'city' => 'Noida',
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'steel fabrication in Noida, budget ₹8 lakh'])
            ->assertOk()
            ->assertJsonPath('data.budget_max', 800000);
    }

    /**
     * A number that is a quantity must not be read as a budget.
     *
     * "500 chairs" has no money cue, so inferring a ₹500 ceiling would silently cap the
     * requirement at five hundred rupees and match it to nobody.
     */
    public function test_a_bare_quantity_is_never_mistaken_for_a_budget(): void
    {
        $this->fakeAi()->willReplyJson([
            'title' => 'Office chairs',
            'category' => 'construction',
            'city' => 'Noida',
            'quantity' => 500,
            'unit' => 'pieces',
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'Need 500 chairs in Noida'])
            ->assertOk()
            ->assertJsonPath('data.quantity', 500)
            ->assertJsonPath('data.budget_max', null)
            ->assertJsonPath('data.budget_min', null);
    }

    /* ------------------------------------------------------------------ *
     | Category validation
     * ------------------------------------------------------------------ */

    public function test_an_invented_category_is_rejected_and_never_created(): void
    {
        $this->fakeAi()->willReplyJson([
            'title' => 'Drone light show',
            // No such row exists.
            'category' => 'drone-light-shows',
            'city' => 'Noida',
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'drone light show for a product launch'])
            ->assertOk();

        $response->assertJsonPath('data.category', null);
        // JSON has no float/int distinction for 0.0, so compare numerically.
        $this->assertSame(0, (int) $response->json('meta.confidence.category'));
        $response->assertJsonPath('meta.ready_to_draft', false);
        // The buyer is asked rather than silently assigned a wrong category.
        $this->assertNotEmpty($response->json('meta.questions'));

        $this->assertDatabaseMissing('categories', ['slug' => 'drone-light-shows']);
        $this->assertSame(2, Category::count());
    }

    public function test_a_category_name_is_accepted_where_a_slug_was_asked_for(): void
    {
        // Models routinely return the label however firmly they are told otherwise.
        $this->fakeAi()->willReplyJson([
            'title' => 'CRM build',
            'category' => 'IT & Software',
            'city' => 'Noida',
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'need a CRM built'])
            ->assertOk()
            ->assertJsonPath('data.category', 'it-software')
            ->assertJsonPath('meta.confidence.category', 0.95);
    }

    public function test_an_ambiguous_category_offers_real_options_instead_of_guessing(): void
    {
        Category::factory()->create(['name' => 'Web Design', 'slug' => 'web-design']);
        Category::factory()->create(['name' => 'Web Development', 'slug' => 'web-development']);

        $this->fakeAi()->willReplyJson(['title' => 'Website', 'category' => 'web', 'city' => 'Noida']);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'I need a web thing'])
            ->assertOk();

        $options = $response->json('meta.category_options');

        $this->assertNotEmpty($options);
        // Every option offered is a row that exists.
        foreach ($options as $option) {
            $this->assertDatabaseHas('categories', ['slug' => $option['slug']]);
        }
    }

    /* ------------------------------------------------------------------ *
     | Location validation
     * ------------------------------------------------------------------ */

    public function test_a_known_city_resolves_against_the_database(): void
    {
        $this->fakeAi()->willReplyJson([
            'title' => 'Interior work', 'category' => 'construction', 'city' => 'Noida',
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'interior work in Noida'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Noida')
            ->assertJsonPath('meta.resolution.location_known', true);
    }

    /**
     * An unknown city is reported, not invented and not silently created here.
     *
     * A pan-India marketplace will always meet towns the seed data missed, so the buyer
     * is not blocked — but nothing is inserted by the extractor.
     */
    public function test_an_unknown_city_is_flagged_without_creating_a_location_row(): void
    {
        $before = Location::count();

        $this->fakeAi()->willReplyJson([
            'title' => 'Borewell drilling', 'category' => 'construction', 'city' => 'Chikkaballapur',
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'borewell drilling in Chikkaballapur'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Chikkaballapur')
            ->assertJsonPath('meta.resolution.location_known', false);

        $this->assertSame($before, Location::count());
        $this->assertDatabaseMissing('locations', ['city' => 'Chikkaballapur']);
    }

    public function test_a_city_alias_resolves_to_the_real_row(): void
    {
        Location::factory()->create(['city' => 'Bengaluru', 'state' => 'Karnataka']);

        $this->fakeAi()->willReplyJson([
            'title' => 'App build', 'category' => 'it-software', 'city' => 'Bangalore',
        ]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'mobile app in Bangalore'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Bengaluru')
            ->assertJsonPath('meta.resolution.location_known', true);
    }

    /* ------------------------------------------------------------------ *
     | Multilingual
     * ------------------------------------------------------------------ */

    public function test_it_handles_hinglish_input(): void
    {
        $this->fakeAi()->willReplyJson([
            'title' => 'Website development',
            'category' => 'it-software',
            'city' => 'Delhi',
            'timeline_text' => '2 mahine',
        ]);

        Location::factory()->create(['city' => 'Delhi', 'state' => 'Delhi']);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'Mujhe Delhi mein website banwani hai'])
            ->assertOk();

        $response->assertJsonPath('data.category', 'it-software');
        $response->assertJsonPath('data.city', 'Delhi');
        // The Hindi duration is parsed by our own normaliser, not the model.
        $this->assertNotNull($response->json('data.required_date'));
    }

    public function test_a_hinglish_budget_and_deadline_are_parsed_deterministically(): void
    {
        // The model returns nothing usable; the deterministic layer still recovers both.
        $this->fakeAi()->willReplyJson([]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', [
                'input' => 'Delhi NCR mein office interior chahiye, budget 20 lakh, 3 mahine mein',
            ])
            ->assertOk();

        $response->assertJsonPath('data.budget_max', 2000000);
        $this->assertNotNull($response->json('data.required_date'));
    }

    /* ------------------------------------------------------------------ *
     | Robustness
     * ------------------------------------------------------------------ */

    public function test_a_malformed_model_response_degrades_instead_of_failing(): void
    {
        // Not JSON at all.
        $this->fakeAi()->willReply('I am terribly sorry, I cannot help with that request.');

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'construction work in Noida, budget 5 lakh'])
            ->assertOk();

        // The deterministic parsers still produced something useful.
        $response->assertJsonPath('data.budget_max', 500000);
        $this->assertNotNull($response->json('data.title'));
    }

    /**
     * An outage is a different failure from a malformed reply, and must not be dressed up
     * as success.
     *
     * With no extraction there is no city and no category, so a 200 with a near-empty
     * draft would tell the buyer the feature worked when it did not. A 503 naming the
     * manual form is the honest answer — and it is what lets the frontend hide the AI
     * affordance rather than render a useless result.
     */
    public function test_a_provider_outage_returns_503_rather_than_a_hollow_draft(): void
    {
        $this->fakeAi()->willFail(new AiUnavailableException('provider down'));

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'I need 500 chairs in Noida, budget 5 lakh'])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.fallback', 'The standard requirement form is unaffected and can be used as normal.');

        // And the manual path genuinely still works.
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', [
                'category_id' => $this->construction->id,
                'title' => 'Manual fallback requirement',
                'quantity' => 500,
            ])
            ->assertCreated();
    }

    public function test_the_quota_being_spent_also_returns_503_not_a_hollow_draft(): void
    {
        $this->fakeAi()->willReplyJson(['title' => 'x', 'category' => 'construction']);
        // No allowance left at all.
        config(['ai.limits.requests_per_day.customer' => -1]);
        config(['ai.limits.requests_per_day.customer' => 1]);

        // Spend the single request.
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'first request uses the allowance'])
            ->assertOk();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'second request is over quota'])
            ->assertStatus(429)
            ->assertJsonPath('meta.fallback', 'The standard requirement form is unaffected and can be used as normal.');
    }

    public function test_the_builder_returns_a_fallback_notice_when_ai_is_disabled(): void
    {
        $this->fakeAi();
        $this->disableAiFeature('requirement_builder');

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'anything at all here'])
            ->assertStatus(503)
            ->assertJsonPath('meta.fallback', 'The standard requirement form is unaffected and can be used as normal.');

        // The manual path is genuinely unaffected.
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/requirements', [
                'category_id' => $this->construction->id,
                'title' => 'Manual requirement',
                'quantity' => 10,
            ])
            ->assertCreated();
    }

    public function test_only_customers_can_use_the_requirement_builder(): void
    {
        $this->fakeAi()->willReplyJson(['title' => 'x', 'category' => 'construction']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/ai/requirement-builder', ['input' => 'I want to post a requirement'])
            ->assertForbidden();
    }

    public function test_the_builder_requires_authentication(): void
    {
        $this->fakeAi();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/ai/requirement-builder', ['input' => 'something'])->assertUnauthorized();
    }

    /* ------------------------------------------------------------------ *
     | Draft creation still requires confirmation
     * ------------------------------------------------------------------ */

    public function test_extraction_then_confirmation_produces_a_draft_never_a_published_requirement(): void
    {
        $this->fakeAi()
            ->willCallTool('create_requirement_draft', [
                'category' => 'construction',
                'title' => 'Office interior fit-out',
                'city' => 'Noida',
                'quantity' => 3000,
                'unit' => 'sq ft',
                'budget_max' => 1500000,
                'required_date' => now()->addMonths(3)->toDateString(),
            ])
            ->willReply('Shall I create that draft?');

        $conversation = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/conversations', ['type' => 'requirement_builder'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/ai/conversations/$conversation/messages", [
                'message' => 'office interior 3000 sq ft in Noida, 15 lakh, 3 months',
            ])
            ->assertOk();

        $this->assertDatabaseCount('requirements', 0);

        $action = AiAction::firstOrFail();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/ai/actions/{$action->uuid}/confirm")
            ->assertOk();

        $requirement = Requirement::firstOrFail();

        $this->assertSame(RequirementStatus::Draft, $requirement->status);
        $this->assertSame($this->customer->id, $requirement->customer_id);
        $this->assertSame($this->construction->id, $requirement->category_id);
        $this->assertSame('Noida', $requirement->location?->city);
        $this->assertSame('1500000.00', $requirement->budget_max);
        // Publishing remains a human act.
        $this->assertNull($requirement->published_at);
    }

    /* ------------------------------------------------------------------ *
     | Natural language search
     * ------------------------------------------------------------------ */

    public function test_natural_language_search_translates_to_filters_and_returns_real_businesses(): void
    {
        $logistics = Category::factory()->create(['name' => 'Logistics', 'slug' => 'logistics']);

        $verified = Business::factory()->create([
            'name' => 'Northline Freight',
            'category_id' => $logistics->id,
            'location_id' => $this->noida->id,
            'verification_status' => 'verified',
            'rating' => 4.6,
            'review_count' => 12,
        ]);

        // A supplier that should be filtered out by the verified-only filter.
        Business::factory()->create([
            'name' => 'Unverified Movers',
            'category_id' => $logistics->id,
            'location_id' => $this->noida->id,
            'verification_status' => 'unverified',
        ]);

        $this->fakeAi()->willReplyJson([
            'category' => 'logistics',
            'city' => 'Noida',
            'verified_only' => true,
            'capacity' => '10 ton shipments',
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/search', [
                'query' => 'Find verified logistics companies near Noida that can handle 10 ton shipments',
            ])
            ->assertOk();

        $response->assertJsonPath('meta.used_ai', true);
        $response->assertJsonPath('meta.interpretation.category', 'Logistics');
        $response->assertJsonPath('meta.interpretation.city', 'Noida');
        $response->assertJsonPath('meta.interpretation.verified_only', true);
        // Capacity is reported as mentioned rather than silently ignored.
        $response->assertJsonPath('meta.interpretation.capacity_mentioned', '10 ton shipments');

        $names = collect($response->json('data'))->pluck('attributes.name');
        $this->assertContains('Northline Freight', $names);
        $this->assertNotContains('Unverified Movers', $names);

        // Explanations are grounded in the returned rows.
        $reasons = $response->json("meta.explanations.{$verified->slug}");
        $this->assertContains('Works in Logistics', $reasons);
        $this->assertContains('Platform-verified supplier', $reasons);
        $this->assertContains('Based in Noida', $reasons);
    }

    public function test_search_never_invents_a_business_when_nothing_matches(): void
    {
        // A real category that genuinely has no suppliers behind it.
        Category::factory()->create(['name' => 'Deep Sea Salvage', 'slug' => 'deep-sea-salvage']);

        $this->fakeAi()->willReplyJson(['category' => 'deep-sea-salvage', 'city' => 'Noida']);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/search', ['query' => 'deep sea salvage contractors in Noida'])
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_an_invented_category_becomes_an_ignored_filter_not_a_broken_query(): void
    {
        $this->fakeAi()->willReplyJson(['category' => 'quantum-teleportation', 'city' => 'Noida']);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/search', ['query' => 'quantum teleportation suppliers in Noida'])
            ->assertOk();

        $this->assertArrayNotHasKey('category', $response->json('meta.filters_applied'));
        // Reported so the UI can say what it could not match.
        $this->assertNotEmpty($response->json('meta.not_applied'));
    }

    public function test_search_falls_back_to_keywords_when_the_provider_is_down(): void
    {
        Business::factory()->create([
            'name' => 'Nagpur Steel Works',
            'category_id' => $this->construction->id,
            'location_id' => $this->noida->id,
        ]);

        $this->fakeAi()->willFail(new AiUnavailableException('down'));

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/ai/search', ['query' => 'Nagpur Steel'])
            ->assertOk();

        // Still 200, still results — just less precisely filtered, and it says so.
        $response->assertJsonPath('meta.used_ai', false);
        $response->assertJsonPath('meta.fallback_notice', 'The assistant was unavailable, so this was searched as plain keywords.');
        $this->assertSame(1, $response->json('meta.total'));
    }

    /* ------------------------------------------------------------------ *
     | Suggestions
     * ------------------------------------------------------------------ */

    public function test_suggestions_come_from_real_categories_and_published_capabilities(): void
    {
        $webDev = Category::factory()->create(['name' => 'Website Development', 'slug' => 'website-development']);
        Category::factory()->create(['name' => 'Website Maintenance', 'slug' => 'website-maintenance']);

        $business = Business::factory()->create([
            'category_id' => $webDev->id,
            'location_id' => $this->noida->id,
        ]);
        Service::factory()->create([
            'business_id' => $business->id,
            'category_id' => $webDev->id,
            'name' => 'Website redesign and migration',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/ai/search/suggestions?term=website')
            ->assertOk();

        $categoryNames = collect($response->json('data.categories'))->pluck('name');
        $this->assertContains('Website Development', $categoryNames);
        $this->assertContains('Website Maintenance', $categoryNames);

        // Real published services, not generic guesses.
        $this->assertContains(
            'Website redesign and migration',
            collect($response->json('data.capabilities'))->pluck('label')->all()
        );

        // No provider call — suggestions must be instant and free.
        $this->assertSame('database', $response->json('meta.source'));
        $this->assertDatabaseCount('ai_requests', 0);
    }

    public function test_suggestions_never_offer_something_no_supplier_provides(): void
    {
        $business = Business::factory()->create([
            'category_id' => $this->construction->id,
            'location_id' => $this->noida->id,
        ]);
        // Inactive, so it must not be suggested.
        Product::factory()->create([
            'business_id' => $business->id,
            'category_id' => $this->construction->id,
            'name' => 'Retired scaffolding line',
            'is_active' => false,
        ]);

        $labels = collect(
            $this->actingAs($this->customer, 'sanctum')
                ->getJson('/api/v1/ai/search/suggestions?term=scaffolding')
                ->assertOk()
                ->json('data.capabilities')
        )->pluck('label');

        $this->assertNotContains('Retired scaffolding line', $labels);
    }

    public function test_a_short_term_returns_popular_industries_rather_than_noise(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/ai/search/suggestions?term=a')
            ->assertOk();

        $this->assertNotEmpty($response->json('data.categories'));
        $this->assertSame([], $response->json('data.capabilities'));
    }
}
