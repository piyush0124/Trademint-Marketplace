<?php

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Enums\VerificationStatus;
use App\Models\Business;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * XML sitemaps, generated from the database.
 *
 * ---------------------------------------------------------------------------
 * WHY A SITEMAP INDEX AND NOT ONE FILE
 * ---------------------------------------------------------------------------
 * The protocol caps a single sitemap at 50,000 URLs and 50MB. With 210 listings
 * today one file would do, but a marketplace whose whole purpose is accumulating
 * listings will pass that, and discovering the limit in production means a silently
 * truncated sitemap. Splitting by type also means a crawler fetching
 * `sitemap-listings.xml` is not re-reading the static pages every time.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELIBERATELY EXCLUDED
 * ---------------------------------------------------------------------------
 * Only content a signed-out visitor can actually load is listed. Submitting a URL
 * that returns 401 or redirects to a login wastes crawl budget and, repeated across
 * a site, is read as low quality:
 *
 *   - Dashboards, admin, orders, quotes — private, and `noindex` besides.
 *   - Draft, pending, rejected and archived listings — not publicly viewable.
 *   - Suspended businesses — excluded from discovery everywhere else, so listing
 *     them here would contradict the application's own rules.
 *   - Filtered result URLs (`?category=`, `?city=`) — the same content in a
 *     different order. Including thousands of them splits ranking across
 *     near-duplicates, which is the classic way a marketplace de-indexes itself.
 *     Category and city *landing* paths are included; arbitrary filter
 *     combinations are not.
 *
 * ---------------------------------------------------------------------------
 * lastmod IS REAL
 * ---------------------------------------------------------------------------
 * Taken from `updated_at`, never `now()`. A sitemap that claims every URL changed
 * today is one a crawler learns to disbelieve, and it then ignores the dates when
 * something genuinely has changed.
 */
class SitemapController extends Controller
{
    /**
     * Cache duration for the generated XML.
     *
     * An hour is long enough that a crawler hammering the index costs nothing, and
     * short enough that a newly published listing is discoverable the same day.
     */
    private const CACHE_SECONDS = 3600;

    /** The sitemap index — the only URL that needs to appear in robots.txt. */
    public function index(): Response
    {
        $sitemaps = ['pages', 'listings', 'businesses', 'categories'];

        $xml = view('sitemap.index', [
            'sitemaps' => array_map(fn (string $name) => [
                'loc' => url('/sitemap-'.$name.'.xml'),
                // Formatted here rather than in the view. Carbon::parse in a Blade
                // expression obscures where a null or malformed timestamp would blow
                // up, and the view should not be doing date arithmetic.
                'lastmod' => $this->formatTimestamp($this->lastModifiedFor($name)),
            ], $sitemaps),
        ])->render();

        return $this->xml($xml);
    }

    /** Static marketing and discovery pages. */
    public function pages(): Response
    {
        $urls = [
            // Priority is a hint and only meaningful *relative* to our own URLs.
            // The homepage and the two discovery surfaces are the entry points.
            ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => url('/suppliers'), 'priority' => '0.9', 'changefreq' => 'daily'],
            ['loc' => url('/browse'), 'priority' => '0.9', 'changefreq' => 'daily'],
            ['loc' => url('/register'), 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => url('/register/owner'), 'priority' => '0.6', 'changefreq' => 'monthly'],
        ];

        return $this->xml(view('sitemap.urlset', ['urls' => $urls])->render());
    }

    /**
     * Every publicly viewable listing.
     *
     * This is the sitemap that matters: these are the pages intended to rank, and
     * each one is a distinct commercial offer with its own title, description, price
     * and location.
     */
    public function listings(): Response
    {
        $urls = Listing::query()
            ->where('status', ListingStatus::Published->value)
            ->whereNotNull('slug')
            // A listing whose business was suspended is still `published` but is no
            // longer discoverable in the UI, so it must not be advertised here.
            ->whereHas('business', fn ($query) => $query->where('verification_status', '!=', VerificationStatus::Suspended->value))
            ->select(['slug', 'uuid', 'updated_at', 'published_at'])
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (Listing $listing) => [
                'loc' => url($listing->publicPath()),
                'lastmod' => ($listing->updated_at ?? $listing->published_at)?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ])
            ->all();

        return $this->xml(view('sitemap.urlset', ['urls' => $urls])->render());
    }

    /** Public supplier profiles. */
    public function businesses(): Response
    {
        $urls = Business::query()
            ->where('verification_status', '!=', VerificationStatus::Suspended->value)
            ->select(['slug', 'updated_at', 'is_verified'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Business $business) => [
                'loc' => url('/business/'.$business->slug),
                'lastmod' => $business->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                /*
                 * Verified profiles are ranked slightly higher among our own pages.
                 * They carry more evidence — documents reviewed, checks confirmed —
                 * so they are the ones we would rather a searcher landed on.
                 */
                'priority' => $business->is_verified ? '0.8' : '0.6',
            ])
            ->all();

        return $this->xml(view('sitemap.urlset', ['urls' => $urls])->render());
    }

    /**
     * Category and city landing paths.
     *
     * These are the only parameterised URLs included, and only in their bare form.
     * They are genuine landing pages with distinct intent ("logistics suppliers in
     * Pune"); combinations of them are not.
     *
     * Cities are drawn from locations that actually hold a published listing, so we
     * never advertise an empty results page.
     */
    public function categories(): Response
    {
        $categoryUrls = Category::query()
            ->where('is_active', true)
            ->select(['slug', 'updated_at'])
            ->get()
            ->map(fn (Category $category) => [
                'loc' => url('/suppliers?category='.$category->slug),
                'lastmod' => $category->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ]);

        $cityUrls = Location::query()
            ->select('city')
            ->distinct()
            ->whereIn('id', Listing::query()
                ->where('status', ListingStatus::Published->value)
                ->whereNotNull('location_id')
                ->select('location_id'))
            ->orderBy('city')
            ->pluck('city')
            ->map(fn (string $city) => [
                'loc' => url('/suppliers?city='.urlencode($city)),
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ]);

        return $this->xml(view('sitemap.urlset', [
            'urls' => $categoryUrls->concat($cityUrls)->all(),
        ])->render());
    }

    /**
     * robots.txt, served dynamically so the sitemap URL matches the deployed host.
     *
     * A static file has to hardcode the domain, which then points at the wrong host
     * on staging — and a staging robots.txt advertising the production sitemap is a
     * good way to get preview content indexed.
     */
    public function robots(Request $request): Response
    {
        $lines = [
            'User-agent: *',
            '',
            '# Private surfaces. Disallowed as well as noindex-tagged: a crawler should',
            '# not spend budget discovering URLs that will only return 401.',
            'Disallow: /dashboard',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /login',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            '',
            '# Filtered and sorted result URLs are the same content reordered. Crawling',
            '# them splits ranking across near-duplicates and burns budget that should',
            '# go to listing pages.',
            'Disallow: /*?*sort=',
            'Disallow: /*?*page=',
            'Disallow: /*?*per_page=',
            '',
            'Allow: /',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }

    private function lastModifiedFor(string $name): ?string
    {
        return match ($name) {
            'listings' => Listing::query()->where('status', ListingStatus::Published->value)->max('updated_at'),
            'businesses' => Business::query()->max('updated_at'),
            'categories' => Category::query()->max('updated_at'),
            default => null,
        };
    }

    /** Null-safe ISO-8601, so a table with no rows does not emit an empty lastmod. */
    private function formatTimestamp(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toAtomString();
        } catch (\Throwable) {
            // A malformed stored timestamp must not take the whole sitemap down;
            // omitting lastmod is valid, an unparseable document is not.
            return null;
        }
    }

    /**
     * Wrap a rendered body as an XML response.
     *
     * The declaration is prepended here rather than written in the Blade templates.
     * Emitting `<?xml … ?>` from a Blade `@php echo` line mis-compiles — the `?>`
     * inside the string terminated the compiled PHP and the sitemap index died with
     * a 500 while the other four sitemaps happened to survive. Doing it in PHP is
     * both correct and stops the declaration being duplicated per view.
     */
    private function xml(string $body): Response
    {
        $declaration = '<?xml version="1.0" encoding="UTF-8"?>'."\n";

        return response($declaration.ltrim($body), 200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }
}
