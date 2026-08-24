<?php

namespace App\Models;

use App\Enums\TradeType;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'location_id',
        'category_id',
        'slug',
        'name',
        'business_type',
        'years_in_business',
        'trade_type',
        'logo_path',
        'cover_path',
        'about',
        'phone',
        'whatsapp',
        'email',
        'website',
        'business_hours',
        'is_available',
        'is_verified',
        'gst_number',
        'rating',
        'review_count',
    ];

    protected function casts(): array
    {
        return [
            'trade_type' => TradeType::class,
            'business_hours' => 'array',
            'is_available' => 'boolean',
            'is_verified' => 'boolean',
            'rating' => 'decimal:2',
            'review_count' => 'integer',
            // Phase 9 verification workflow.
            'verification_requested_at' => 'datetime',
            'verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'established_on' => 'date',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'business_verified_at' => 'datetime',
            'address_verified_at' => 'datetime',
            'gst_verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** The business's primary/default category (e.g. "Textiles"). */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** Locations this business delivers/provides service to — distinct from its own registered location. */
    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'business_service_areas')->withTimestamps();
    }

    public function industries(): BelongsToMany
    {
        return $this->belongsToMany(Industry::class, 'business_industry')->withTimestamps();
    }

    /** Requirement invitations produced for this business by the matching engine. */
    public function requirementMatches(): HasMany
    {
        return $this->hasMany(RequirementMatch::class);
    }

    /** Enquiries this business has received from customers (Phase 5). */
    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class);
    }

    /** Orders won (Phase 7). */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Payments received (Phase 8). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Customer reviews (Phase 9). */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** Verification documents. Private — never exposed beyond type and status. */
    public function documents(): HasMany
    {
        return $this->hasMany(BusinessDocument::class);
    }

    /**
     * The admin-controlled verification state. Read through a helper because the
     * column was added in Phase 9 and older rows may predate it.
     */
    public function verificationStatus(): VerificationStatus
    {
        return VerificationStatus::tryFrom((string) $this->verification_status)
            ?? VerificationStatus::Unverified;
    }

    /** Suspended businesses are excluded from discovery and new matching. */
    public function isSuspended(): bool
    {
        return $this->verificationStatus()->isSuspended();
    }

    /** Excludes suspended businesses from any "available supplier" query. */
    public function scopeNotSuspended(Builder $query): Builder
    {
        return $query->where('verification_status', '!=', VerificationStatus::Suspended->value);
    }

    /** Quotes this business has issued. */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null;
    }

    /**
     * "Fully verified" means every dimension has actually been checked —
     * never inferred from a single self-reported flag. A business with a
     * GST number that was never actually verified is not fully verified.
     */
    public function isFullyVerified(): bool
    {
        return $this->email_verified_at !== null
            && $this->phone_verified_at !== null
            && $this->business_verified_at !== null
            && $this->address_verified_at !== null
            && ($this->gst_number === null || $this->gst_verified_at !== null);
    }
}
