<?php

namespace Database\Seeders;

use App\Enums\AvailabilityStatus;
use App\Enums\ListingStatus;
use App\Enums\RequirementStatus;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\Business;
use App\Models\Category;
use App\Models\Enquiry;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\Demo\DemoCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ============================================================================
 * DEMO / DEVELOPMENT DATA ONLY — NEVER RUN IN PRODUCTION
 * ============================================================================
 *
 * Seeds a browsable marketplace: ~72 fictional supplier businesses across twelve
 * sectors and twenty Indian cities, carrying 210 listings, plus the services,
 * service areas and genuinely-earned reviews that make the discovery, filter and
 * sort surfaces worth looking at.
 *
 * ----------------------------------------------------------------------------
 * PRODUCTION GUARD
 * ----------------------------------------------------------------------------
 * This seeder refuses to run when the application environment is `production`.
 * That is a hard abort, not a warning, and it is not overridable by a flag —
 * a flag is something someone sets by accident at 2am. `DatabaseSeeder` does
 * not call this class at all; it has to be requested by name:
 *
 *     php artisan db:seed --class=DemoMarketplaceSeeder
 *
 * ----------------------------------------------------------------------------
 * IDEMPOTENCY
 * ----------------------------------------------------------------------------
 * Every write is a firstOrCreate against a natural key (user email, business
 * slug, listing title within a business). Running this twice produces the same
 * database as running it once — it will not double the listing count, and it
 * will not reset a password on an account someone is already using locally.
 *
 * ----------------------------------------------------------------------------
 * WHY RATINGS ARE DERIVED, NOT INVENTED
 * ----------------------------------------------------------------------------
 * `businesses.rating` and `review_count` are computed from the reviews this
 * seeder actually creates, and each of those reviews hangs off a real completed
 * order, which hangs off a real quote, enquiry and requirement.
 *
 * Writing a rating of 4.8 onto a business with no reviews would be quicker, and
 * it is what most demo data does, but it produces two facts that disagree: a
 * profile claiming forty reviews above a review tab that is empty. Roughly a
 * third of businesses are therefore left genuinely unrated, which also gives
 * the "no reviews yet" empty state something real to render.
 */
class DemoMarketplaceSeeder extends Seeder
{
    /**
     * Listing status mix.
     *
     * The brief asks for 70/15/10/5 published/draft/pending/suspended. There is
     * no `suspended` case on ListingStatus — the closest real state an admin can
     * put a listing into is `archived`, so the 5% lands there. Inventing an enum
     * case to match a document would be the wrong way round.
     */
    private const STATUS_MIX = [
        [ListingStatus::Published, 70],
        [ListingStatus::Draft, 15],
        [ListingStatus::PendingReview, 10],
        [ListingStatus::Archived, 5],
    ];

    /** Listings per supplier. Keeps businesses from looking like single-item shells. */
    private const LISTINGS_PER_BUSINESS = 3;

    /**
     * Cities that earlier versions of LocationSeeder wrote under a different name,
     * mapped to the name now used. Data attached to the old row is moved onto the
     * canonical row so the city filter does not offer the same place twice.
     */
    private const CITY_ALIASES = [
        'Bangalore' => 'Bengaluru',
    ];

    private array $locationsByCity = [];

    /**
     * Listing counter shared across every sector.
     *
     * Deliberately global rather than reset per sector. The status pattern is 20
     * slots long, and restarting it at each sector boundary meant every sector
     * shorter than 20 listings only ever saw the front of the pattern — which
     * pushed the published share to 79% against a 70% target.
     */
    private int $listingSerial = 0;

    private array $counters = [
        'owners' => 0, 'businesses' => 0, 'listings' => 0,
        'services' => 0, 'service_areas' => 0, 'reviews' => 0, 'customers' => 0,
        'cities_merged' => 0, 'verification_repaired' => 0,
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoMarketplaceSeeder is development-only and refuses to run in production.');

            return;
        }

        $this->normaliseLegacyCities();
        $this->reconcileVerificationState();
        $this->loadLocations();

        if ($this->locationsByCity === []) {
            $this->command?->error('No locations found. Run LocationSeeder first (php artisan db:seed).');

            return;
        }

        $customers = $this->demoCustomers();
        $cityCursor = 0;

        foreach (DemoCatalogue::sectors() as $sectorName => $sector) {
            $category = Category::query()->where('slug', $sector['category'])->first();

            if ($category === null) {
                $this->command?->warn(sprintf('  Category "%s" missing, skipping %s.', $sector['category'], $sectorName));

                continue;
            }

            $this->seedSector($sectorName, $sector, $category, $customers, $cityCursor);
        }

        $this->report();
    }

    /**
     * Fold legacy city spellings into the canonical row.
     *
     * A development database seeded before LocationSeeder switched to current
     * official names holds both "Bangalore" and "Bengaluru", and because this
     * seeder spreads suppliers across whatever cities exist, both ended up
     * populated — the city filter offered the same place twice, with the
     * listings split between the two entries.
     *
     * Rows are matched by (state, city, area) so a legacy area maps onto its
     * counterpart rather than onto an arbitrary area of the right city. Anything
     * still referenced after remapping is left alone: a delete only happens once
     * the row is provably unreferenced by listings, businesses and service areas.
     */
    private function normaliseLegacyCities(): void
    {
        foreach (self::CITY_ALIASES as $legacyCity => $canonicalCity) {
            $legacyRows = Location::query()->where('city', $legacyCity)->get();

            foreach ($legacyRows as $legacy) {
                $canonical = Location::query()
                    ->where('city', $canonicalCity)
                    ->where('area', $legacy->area)
                    ->first()
                    // No matching area under the canonical name: fall back to any
                    // area of that city rather than inventing one.
                    ?? Location::query()->where('city', $canonicalCity)->first();

                if ($canonical === null) {
                    continue;
                }

                Listing::query()->where('location_id', $legacy->id)->update(['location_id' => $canonical->id]);
                Business::query()->where('location_id', $legacy->id)->update(['location_id' => $canonical->id]);

                // Service areas carry a (business, location) unique key, so a
                // straight update can collide with a row the business already has.
                // Re-insert with insertOrIgnore, then drop the originals.
                $areas = DB::table('business_service_areas')->where('location_id', $legacy->id)->get();

                foreach ($areas as $area) {
                    DB::table('business_service_areas')->insertOrIgnore([
                        'business_id' => $area->business_id,
                        'location_id' => $canonical->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('business_service_areas')->where('location_id', $legacy->id)->delete();

                $stillUsed = Listing::query()->where('location_id', $legacy->id)->exists()
                    || Business::query()->where('location_id', $legacy->id)->exists()
                    || DB::table('business_service_areas')->where('location_id', $legacy->id)->exists()
                    || DB::table('requirements')->where('location_id', $legacy->id)->exists();

                if (! $stillUsed) {
                    $legacy->delete();
                    $this->counters['cities_merged']++;
                }
            }
        }
    }

    /**
     * Bring the three verification representations back into agreement.
     *
     * Runs before the main seed and independently of it, because the per-business
     * work below short-circuits on an existing slug — so a fix to how businesses are
     * created cannot repair the ones already in a developer's database.
     *
     * The drift this repairs: businesses written with `is_verified = true` and the
     * four granular timestamps, but with `verification_status` left at its
     * 'unverified' default. That made a profile show an "Unverified" badge above a
     * checklist reporting four confirmed checks.
     *
     * Scoped to demo data only, by the `@demo.marketplace.test` owner address. A
     * seeder must not silently rewrite verification state on records it did not
     * create — that is an admin decision, and quietly marking a real business
     * verified would be a security change disguised as a data fix.
     */
    private function reconcileVerificationState(): void
    {
        $demoBusinessIds = Business::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'like', '%@demo.marketplace.test'))
            ->where('is_verified', true)
            ->where('verification_status', VerificationStatus::Unverified->value)
            ->pluck('id');

        if ($demoBusinessIds->isEmpty()) {
            return;
        }

        foreach (Business::query()->whereIn('id', $demoBusinessIds)->get() as $business) {
            $business->forceFill([
                'verification_status' => VerificationStatus::Verified->value,
                'verified_at' => $business->business_verified_at ?? now()->subDays(35),
            ])->save();

            $this->counters['verification_repaired']++;
        }
    }

    /**
     * Cities are indexed once and drawn round-robin so the dataset spreads evenly.
     *
     * A random city per business sounds equivalent but is not: with 72 draws over
     * 20 cities, chance alone leaves some cities empty and others with five or six.
     * Round-robin guarantees every city has suppliers, which is the property that
     * makes location filtering demonstrable — and it is the mechanism that stops
     * any one city, Kolkata included, from dominating.
     */
    private function loadLocations(): void
    {
        /*
         * Restricted to the canonical twenty cities, not every row in `locations`.
         *
         * The table accumulates rows from other sources: owner registration inserts
         * whatever city a real user typed, and several factories create a Location
         * with a faker place name. Indexing all of them meant the demo dataset spread
         * suppliers and service areas across invented cities like "East Felixstad",
         * which then appeared in the public city filter alongside Mumbai and Pune.
         *
         * That is exactly what happened: nine `business_service_areas` rows per faker
         * city, created by a later re-run of this seeder once those rows existed.
         * Anchoring to LocationSeeder::cityNames() means stray locations can never be
         * pulled into the demo data, however they got there.
         */
        $canonicalCities = LocationSeeder::cityNames();

        $locations = Location::query()
            ->whereIn('city', $canonicalCities)
            ->orderBy('city')
            ->orderBy('id')
            ->get();

        foreach ($locations as $location) {
            $this->locationsByCity[$location->city][] = $location;
        }
    }

    /**
     * Demo buyers. These are the accounts that raise the requirements the reviews
     * are eventually attached to.
     */
    private function demoCustomers(): array
    {
        $names = [
            'Ananya Sharma', 'Rohit Menon', 'Kavya Reddy', 'Imran Qureshi',
            'Neha Bhatt', 'Arjun Nair', 'Priya Iyer', 'Vikram Desai',
        ];

        $customers = [];

        foreach ($names as $index => $name) {
            $email = sprintf('buyer%d@demo.marketplace.test', $index + 1);
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null) {
                $customers[] = $existing;

                continue;
            }

            $customers[] = User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'role' => UserRole::Customer,
                'email_verified_at' => now(),
            ]);
            $this->counters['customers']++;
        }

        return $customers;
    }

    private function seedSector(string $sectorName, array $sector, Category $category, array $customers, int &$cityCursor): void
    {
        $subcategories = Category::query()->where('parent_id', $category->id)->get();
        $cities = array_keys($this->locationsByCity);

        $businessCount = (int) ceil($sector['listings'] / self::LISTINGS_PER_BUSINESS);
        $remaining = $sector['listings'];
        // Per-sector counter, used to walk this sector's own offering and price pools.
        // Distinct from $this->listingSerial, which is global and drives status.
        $offeringSerial = 0;

        for ($i = 0; $i < $businessCount; $i++) {
            // Round-robin the city list, advancing a cursor shared across sectors so
            // sector N does not start at the same city as sector N-1.
            $city = $cities[$cityCursor % count($cities)];
            $cityCursor++;

            $areas = $this->locationsByCity[$city];
            $homeLocation = $areas[$i % count($areas)];

            $business = $this->makeBusiness($sectorName, $sector, $category, $homeLocation, $i);

            if ($business === null) {
                continue;
            }

            $this->attachServices($business, $sector, $category);
            $this->attachServiceAreas($business, $city, $cities);

            // Distribute the sector's listing count across its businesses, giving the
            // remainder to the last one rather than silently losing it.
            $share = min(self::LISTINGS_PER_BUSINESS, $remaining);

            if ($i === $businessCount - 1) {
                $share = $remaining;
            }

            for ($j = 0; $j < $share; $j++) {
                $this->makeListing($business, $sector, $category, $subcategories, $homeLocation, $offeringSerial);
                $offeringSerial++;
            }

            $remaining -= $share;

            // Two businesses in three earn reviews; the rest stay genuinely unrated.
            if ($i % 3 !== 2) {
                $this->attachReviews($business, $category, $customers, $i);
            }
        }
    }

    private function makeBusiness(string $sectorName, array $sector, Category $category, Location $location, int $index): ?Business
    {
        $stem = DemoCatalogue::NAME_STEMS[
            // Offset by sector so two sectors do not both open with "NorthStar".
            (crc32($sectorName) + $index) % count(DemoCatalogue::NAME_STEMS)
        ];
        $descriptor = $sector['descriptors'][$index % count($sector['descriptors'])];
        $name = $stem.' '.$descriptor;
        $slug = Str::slug($name);

        $existing = Business::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        $ownerEmail = sprintf('%s@demo.marketplace.test', $slug);

        $owner = User::query()->where('email', $ownerEmail)->first()
            ?? User::factory()->create([
                'name' => $stem.' (Demo Supplier)',
                'email' => $ownerEmail,
                'password' => 'password',
                'role' => UserRole::Owner,
                'email_verified_at' => now(),
            ]);

        if ($owner->wasRecentlyCreated) {
            $this->counters['owners']++;
        }

        // Roughly two in three verified, so the verification filter has both sides.
        $isVerified = $index % 3 !== 2;

        $business = Business::query()->create([
            'user_id' => $owner->id,
            'location_id' => $location->id,
            'category_id' => $category->id,
            'slug' => $slug,
            'name' => $name,
            'about' => $sector['summary'],
            'business_type' => $sector['business_type'],
            'trade_type' => $sector['trade_type'],
            'years_in_business' => 3 + ($index * 2) % 18,
            // Reserved 99999xxxxx range and .test TLD: neither can reach anyone real.
            'phone' => '99999'.str_pad((string) (10000 + $this->counters['businesses']), 5, '0', STR_PAD_LEFT),
            'whatsapp' => '99999'.str_pad((string) (10000 + $this->counters['businesses']), 5, '0', STR_PAD_LEFT),
            'email' => 'contact@'.Str::slug($stem).'.demo.test',
            'website' => 'https://'.Str::slug($stem).'.demo.test',
            'is_verified' => $isVerified,
            'is_available' => $index % 7 !== 6,
        ]);

        if ($isVerified) {
            /*
             * All three verification representations have to be set together.
             *
             * This model carries the concept in three places, and they are read by
             * different things:
             *
             *   - `is_verified` (boolean)     — the legacy flag, used by discovery filters
             *   - `verification_status` (enum) — Phase 9 state, drives verificationStatus()
             *                                    and therefore the badge on the passport
             *   - the five `*_verified_at`     — drive isFullyVerified() and the public
             *                                    per-check checklist
             *
             * An earlier version of this seeder set only the first and third. The
             * result was a business showing the badge "Unverified" directly above a
             * checklist reporting four checks "Confirmed" — the profile contradicting
             * itself, which is worse than either answer alone. Caught only once the
             * checklist was actually rendered to buyers.
             *
             * Timestamps are not fillable, by design: an admin action sets them.
             */
            $business->forceFill([
                'verification_status' => VerificationStatus::Verified->value,
                'verified_at' => now()->subDays(35),
                'email_verified_at' => now()->subDays(40),
                'phone_verified_at' => now()->subDays(40),
                'business_verified_at' => now()->subDays(35),
                'address_verified_at' => now()->subDays(35),
            ])->save();
        }

        $this->counters['businesses']++;

        return $business;
    }

    private function attachServices(Business $business, array $sector, Category $category): void
    {
        foreach ($sector['services'] as $position => $serviceName) {
            $service = Service::query()->firstOrCreate(
                ['business_id' => $business->id, 'name' => $serviceName],
                [
                    'category_id' => $category->id,
                    'description' => $serviceName.' for '.strtolower($sector['summary']),
                    'min_order' => $position === 0 ? null : 1,
                    'estimated_delivery_time' => [7, 14, 21, 30][$position % 4].' days',
                    'customization_available' => $position % 2 === 0,
                    'is_active' => true,
                ]
            );

            if ($service->wasRecentlyCreated) {
                $this->counters['services']++;
            }
        }
    }

    /**
     * A supplier serves its home city plus two neighbours, so the service-area
     * filter returns more than the businesses physically registered in a city.
     */
    private function attachServiceAreas(Business $business, string $homeCity, array $cities): void
    {
        $homeIndex = array_search($homeCity, $cities, true);

        foreach ([0, 1, 2] as $offset) {
            $city = $cities[((int) $homeIndex + $offset) % count($cities)];

            foreach (array_slice($this->locationsByCity[$city], 0, 1) as $location) {
                $inserted = DB::table('business_service_areas')->insertOrIgnore([
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->counters['service_areas'] += $inserted;
            }
        }
    }

    private function makeListing(
        Business $business,
        array $sector,
        Category $category,
        $subcategories,
        Location $location,
        int $serial,
    ): void {
        $offerings = $sector['offerings'];
        $qualifiers = DemoCatalogue::QUALIFIERS;

        $offering = $offerings[$serial % count($offerings)];
        // Only qualify once the offering pool has been through a full cycle, so early
        // titles stay clean and later ones stay distinct.
        $cycle = intdiv($serial, count($offerings));
        $title = $cycle === 0
            ? $offering
            : $offering.' — '.$qualifiers[($serial + $cycle) % count($qualifiers)];

        $existing = Listing::query()
            ->where('business_id', $business->id)
            ->where('title', $title)
            ->first();

        if ($existing !== null) {
            return;
        }

        [$floor, $ceiling] = $sector['price'];
        // Deterministic spread across the sector's band rather than rand(), so a
        // re-seed reproduces the same prices and price-filter tests stay stable.
        $step = ($ceiling - $floor) / max(count($offerings), 1);
        $price = (int) round($floor + $step * ($serial % count($offerings)));
        $priceMax = (int) round($price * 1.45);

        // Status comes off the global counter so the 20-slot pattern completes whole
        // cycles across the whole dataset instead of restarting at each sector.
        $status = $this->statusFor($this->listingSerial);
        $this->listingSerial++;

        $subcategory = $subcategories->isNotEmpty()
            ? $subcategories[$serial % $subcategories->count()]
            : null;

        $createdAt = now()->subDays(5 + ($serial * 7) % 180);

        $listing = Listing::query()->create([
            'user_id' => $business->user_id,
            'business_id' => $business->id,
            // Subcategory where the tree has one, so category and subcategory
            // filtering both have data to bite on.
            'category_id' => $subcategory?->id ?? $category->id,
            'location_id' => $location->id,
            'title' => $title,
            'description' => sprintf(
                '%s %s Available in %s and surrounding areas. Pricing shown is indicative; '
                .'send an enquiry with your quantity and timeline for a firm quotation. '
                .'(Demonstration listing — not a real commercial offer.)',
                $offering.'.',
                $sector['summary'],
                $location->city,
            ),
            'price' => $price,
            'price_max' => $priceMax,
            'currency' => 'INR',
            'unit' => $sector['unit'],
            'min_order_quantity' => $sector['unit'] === 'unit' ? 10 : 1,
            'status' => $status,
            'availability_status' => AvailabilityStatus::Available,
        ]);

        // Workflow timestamps must agree with the status, or the admin queues and
        // "published X ago" labels show a published listing with no publish date.
        $timestamps = ['created_at' => $createdAt, 'updated_at' => $createdAt];

        if ($status === ListingStatus::Published) {
            $timestamps['submitted_at'] = $createdAt;
            $timestamps['published_at'] = $createdAt->copy()->addDays(2);
        } elseif ($status === ListingStatus::PendingReview) {
            $timestamps['submitted_at'] = $createdAt;
        } elseif ($status === ListingStatus::Archived) {
            $timestamps['submitted_at'] = $createdAt;
            $timestamps['published_at'] = $createdAt->copy()->addDays(2);
            $timestamps['archived_at'] = $createdAt->copy()->addDays(90);
        }

        $listing->forceFill($timestamps)->save();

        $this->counters['listings']++;
    }

    /**
     * Deterministic status assignment over a 20-slot pattern.
     *
     * A weighted random draw would give roughly the right mix but a different one
     * per run, so a test asserting "there are published listings to paginate"
     * would be flaky. A fixed pattern hits the ratio exactly and reproducibly.
     *
     * The six non-published slots are spread through the cycle rather than
     * appended to the end. Order matters here: with the statuses grouped
     * (14 published, then 3 draft, then 2 pending, then 1 archived) any run that
     * stops mid-cycle has taken only published rows, which is what produced the
     * 79% published / 6.7% pending skew on the first pass. Interleaving means
     * every prefix of the pattern is close to the target mix too.
     */
    private function statusFor(int $serial): ListingStatus
    {
        static $pattern = null;

        if ($pattern === null) {
            // 20 slots: 70/15/10/5 divides cleanly into fifths of a hundred, giving
            // 14 published, 3 draft, 2 pending review, 1 archived.
            $pattern = array_fill(0, 20, ListingStatus::Published);

            $pattern[4] = ListingStatus::Draft;
            $pattern[7] = ListingStatus::PendingReview;
            $pattern[11] = ListingStatus::Draft;
            $pattern[14] = ListingStatus::PendingReview;
            $pattern[17] = ListingStatus::Draft;
            $pattern[19] = ListingStatus::Archived;
        }

        return $pattern[$serial % count($pattern)];
    }

    /**
     * Reviews, each earned by a real completed order.
     *
     * The full chain is built — requirement, enquiry, quote, order, review — because
     * `reviews.order_id` is a non-nullable unique foreign key. A review without one
     * is a row the application itself could never produce, and seeding it would mean
     * the review tab shows feedback that no order history explains.
     */
    private function attachReviews(Business $business, Category $category, array $customers, int $index): void
    {
        if (Review::query()->where('business_id', $business->id)->exists()) {
            return;
        }

        $comments = [
            5 => 'Scoped the work properly and delivered on the committed date. Documentation was complete.',
            4 => 'Good quality overall. Communication could have been quicker during the middle of the project.',
            3 => 'Work was acceptable but the schedule slipped by about a week against what was quoted.',
        ];

        // Two to four reviews, varied so the average is not identical everywhere.
        $ratings = [[5, 4, 5], [4, 5, 4, 5], [5, 3, 4], [4, 4, 5, 5]][$index % 4];

        foreach ($ratings as $position => $rating) {
            $customer = $customers[($index + $position) % count($customers)];

            $requirement = Requirement::query()->create([
                'customer_id' => $customer->id,
                'category_id' => $category->id,
                'title' => 'Requirement for '.$business->name,
                'description' => 'Demonstration requirement raised to support a completed order and its review.',
                'quantity' => 1,
                'unit' => 'project',
                'budget_min' => 50000,
                'budget_max' => 250000,
                'required_date' => now()->subDays(60)->toDateString(),
                'status' => RequirementStatus::Closed,
            ]);

            $enquiry = Enquiry::factory()->accepted()->create([
                'requirement_id' => $requirement->id,
                'customer_id' => $customer->id,
                'business_id' => $business->id,
            ]);

            $quote = Quote::factory()->accepted()->create([
                'enquiry_id' => $enquiry->id,
                'requirement_id' => $requirement->id,
                'customer_id' => $customer->id,
                'business_id' => $business->id,
            ]);

            $order = Order::factory()->completed()->create([
                'quote_id' => $quote->id,
                'requirement_id' => $requirement->id,
                'customer_id' => $customer->id,
                'business_id' => $business->id,
            ]);

            $review = Review::query()->create([
                'order_id' => $order->id,
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'rating' => $rating,
                'comment' => $comments[$rating] ?? $comments[4],
            ]);

            // Status is not fillable — moderation owns it.
            $review->forceFill([
                'status' => ReviewStatus::Published,
                'created_at' => now()->subDays(30 - $position * 5),
            ])->save();

            $this->counters['reviews']++;
        }

        // Aggregate from what was actually written, so the profile header and the
        // review list can never contradict each other.
        $aggregate = Review::query()
            ->where('business_id', $business->id)
            ->publicallyVisible()
            ->selectRaw('COUNT(*) AS total, AVG(rating) AS average')
            ->first();

        $business->forceFill([
            'rating' => round((float) $aggregate->average, 2),
            'review_count' => (int) $aggregate->total,
        ])->save();
    }

    private function report(): void
    {
        $this->command?->newLine();
        $this->command?->info('Demo marketplace seeded (development data — fictional businesses).');

        foreach ($this->counters as $label => $count) {
            $this->command?->line(sprintf('  %-15s %d', str_replace('_', ' ', $label), $count));
        }

        $published = Listing::query()->where('status', ListingStatus::Published)->count();
        $this->command?->line(sprintf('  %-15s %d', 'total listings', Listing::query()->count()));
        $this->command?->line(sprintf('  %-15s %d', 'published', $published));
        $this->command?->line(sprintf('  %-15s %d', 'cities', Location::query()->distinct()->count('city')));
        $this->command?->newLine();
    }
}
