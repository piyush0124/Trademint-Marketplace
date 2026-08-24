<?php

use App\Models\Listing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Keyword-bearing URLs for listings.
 *
 * ---------------------------------------------------------------------------
 * WHY
 * ---------------------------------------------------------------------------
 * Listings were addressed by UUID: `/listings/a915e971-34d1-4f1d-b67e-df6a5bd21bc0`.
 * That URL tells a search engine nothing, and the URL is one of the few ranking
 * signals a marketplace fully controls. With 210 listings whose entire commercial
 * value is being findable, this was the largest single SEO defect in the product.
 *
 * The slug is `{title}-{first 8 of uuid}`:
 *
 *     /listings/full-truckload-freight-a915e971
 *
 * ---------------------------------------------------------------------------
 * WHY THE UUID FRAGMENT STAYS IN THE SLUG
 * ---------------------------------------------------------------------------
 * Two suppliers legitimately list "Commercial Interior Fit-out", so titles are not
 * unique. The alternatives were a collision counter (`-2`, `-3`), which requires a
 * read-modify-write per insert and still races under concurrency, or a uniqueness
 * loop. An 8-character uuid fragment is unique by construction, needs no lookup,
 * and stays stable if the title is later edited — which matters, because a slug that
 * changes silently breaks every inbound link and any ranking already earned.
 *
 * ---------------------------------------------------------------------------
 * NOT A BREAKING CHANGE
 * ---------------------------------------------------------------------------
 * Nullable, and `Listing::resolveRouteBinding` accepts a uuid or a slug. Every
 * existing `/listings/{uuid}` link — including ones already shared or indexed —
 * keeps resolving. The slug is the new canonical form, not the only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            /*
             * Nullable rather than NOT NULL. A listing can be created as an almost
             * empty draft — `make_listing_core_fields_nullable` established that
             * title itself is optional — so there is not always a title to slugify
             * at insert time. The model fills it in as soon as one exists.
             */
            $table->string('slug')->nullable()->unique()->after('uuid');
            // Published-listing lookups by slug are the hottest public read path.
            $table->index(['slug', 'status']);
        });

        $this->backfill();
    }

    /**
     * Backfill existing rows.
     *
     * Chunked because this runs against real data — 210 rows today, but a migration
     * that loads an entire table into memory is one that fails silently later.
     */
    private function backfill(): void
    {
        Listing::query()->whereNull('slug')->chunkById(200, function ($listings): void {
            foreach ($listings as $listing) {
                $listing->forceFill(['slug' => self::buildSlug($listing->title, $listing->uuid)])->saveQuietly();
            }
        });
    }

    /**
     * Shared with Listing::buildSlug — duplicated here deliberately.
     *
     * A migration must keep working even if the model's logic is later changed or
     * the class is renamed; calling into application code from a migration is how
     * `migrate:fresh` starts failing six months from now. The two are allowed to
     * drift precisely because this one only ever runs once per database.
     */
    private static function buildSlug(?string $title, string $uuid): string
    {
        $base = Str::slug((string) $title);
        $suffix = substr(str_replace('-', '', $uuid), 0, 8);

        if ($base === '') {
            return 'listing-'.$suffix;
        }

        // Bounded so a very long title cannot produce a URL that breaks in email
        // clients or exceeds the column length.
        return Str::limit($base, 80, '').'-'.$suffix;
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['slug', 'status']);
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
