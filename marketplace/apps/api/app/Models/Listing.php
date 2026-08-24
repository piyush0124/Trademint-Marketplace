<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'slug',
        'user_id',
        'business_id',
        'category_id',
        'location_id',
        'title',
        'description',
        'price',
        'price_max',
        'currency',
        'unit',
        'min_order_quantity',
        'status',
        'availability_status',
        'rejection_reason',
        'submitted_at',
        'published_at',
        'rejected_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_max' => 'decimal:2',
            'status' => ListingStatus::class,
            'availability_status' => AvailabilityStatus::class,
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'rejected_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Listing $listing): void {
            if (empty($listing->uuid)) {
                $listing->uuid = (string) Str::uuid();
            }

            if (empty($listing->slug)) {
                $listing->slug = self::buildSlug($listing->title, $listing->uuid);
            }
        });

        /*
         * A draft created without a title gets its slug the moment one is supplied.
         *
         * Deliberately one-way: once a slug exists it is never regenerated, even if
         * the title is rewritten. A slug that follows the title would change the
         * canonical URL of an indexed page on every edit, discarding whatever
         * ranking it had earned and breaking any link already shared. The uuid
         * fragment keeps it unique, so a stale slug is harmless.
         */
        static::updating(function (Listing $listing): void {
            if (empty($listing->slug) && ! empty($listing->title)) {
                $listing->slug = self::buildSlug($listing->title, $listing->uuid);
            }
        });
    }

    /**
     * `{title}-{first 8 of uuid}`, e.g. `full-truckload-freight-a915e971`.
     *
     * The uuid fragment makes it unique without a lookup: titles are not unique on a
     * marketplace ("Commercial Interior Fit-out" appears many times), and a collision
     * counter would need a read before every insert and would still race.
     */
    public static function buildSlug(?string $title, string $uuid): string
    {
        $base = Str::slug((string) $title);
        $suffix = substr(str_replace('-', '', $uuid), 0, 8);

        if ($base === '') {
            return 'listing-'.$suffix;
        }

        return Str::limit($base, 80, '').'-'.$suffix;
    }

    /**
     * The canonical public path for this listing.
     *
     * Centralised so the sitemap, the JSON-LD, the canonical tag and any emailed
     * link cannot disagree about a listing's address — a canonical tag that points
     * somewhere other than the sitemap entry is worse than having neither.
     */
    public function publicPath(): string
    {
        return '/listings/'.($this->slug ?? $this->uuid);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve by slug or uuid.
     *
     * Both are accepted permanently, not as a migration window. Every
     * `/listings/{uuid}` link created before slugs existed — shared in an email,
     * saved in a browser, or already crawled — must keep working, and a marketplace
     * that 404s its own historical URLs loses exactly the inbound traffic this
     * change was meant to attract.
     *
     * Slug is tried first because it is the canonical form and therefore the common
     * case; the uuid branch is the compatibility path.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return $this->where($field, $value)->firstOrFail();
        }

        return $this->where('slug', $value)
            ->orWhere('uuid', $value)
            ->firstOrFail();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ListingAttributeValue::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('sort_order');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::Published);
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::PendingReview);
    }

    public function isPublished(): bool
    {
        return $this->status === ListingStatus::Published;
    }

    public function isDraft(): bool
    {
        return $this->status === ListingStatus::Draft;
    }

    public function isEditableByOwner(): bool
    {
        return in_array($this->status, [ListingStatus::Draft, ListingStatus::Rejected], true);
    }
}
