<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A commercial offer from a business to satisfy a requirement.
 *
 * Money fields are read-only from the client's point of view: they are always
 * recalculated from the line items by QuoteService, never assigned from
 * request input, which is why none of them appear in $fillable.
 *
 * Expiry is a *derived* fact — `hasExpired()` compares expires_at to now
 * rather than trusting the stored status, so a quote whose validity lapsed
 * cannot be accepted even if no background job has run to relabel it yet.
 */
class Quote extends Model
{
    use HasFactory;

    /**
     * Deliberately excludes every monetary column, `status`, `quote_number`
     * and the lifecycle timestamps: those are set by QuoteService, so even a
     * mass-assignment slip cannot let a client price its own quote, change
     * its status, or forge a quote number.
     */
    protected $fillable = [
        'uuid',
        'enquiry_id',
        'requirement_id',
        'customer_id',
        'business_id',
        'title',
        'description',
        'currency',
        'validity_date',
        'estimated_delivery_date',
        'estimated_completion_date',
        'payment_terms',
        'warranty_terms',
        'cancellation_terms',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'validity_date' => 'date',
            'estimated_delivery_date' => 'date',
            'estimated_completion_date' => 'date',
            'status' => QuoteStatus::class,
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $quote): void {
            if (empty($quote->uuid)) {
                $quote->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The immutable offer history, oldest first (Phase 6). */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuoteRevision::class)->orderBy('revision_number');
    }

    /** The offer currently on the table. */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class, 'current_revision_id');
    }

    /** At most one negotiation per quote; created lazily on the first counter. */
    public function negotiation(): HasOne
    {
        return $this->hasOne(Negotiation::class);
    }

    /** The accepted revision — the exact terms an order (Phase 7) is formed from. */
    public function acceptedRevision(): ?QuoteRevision
    {
        return $this->status === QuoteStatus::Accepted ? $this->currentRevision : null;
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    /** Sent or viewed, and still inside its validity window. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }

    /** Past its validity window but not yet relabelled. */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    public function isDraft(): bool
    {
        return $this->status === QuoteStatus::Draft;
    }

    /**
     * Source of truth for expiry. Computed, not stored, so an expired quote is
     * expired the moment its validity passes — no scheduler required.
     */
    public function hasExpired(): bool
    {
        if ($this->status === QuoteStatus::Expired) {
            return true;
        }

        return $this->expires_at !== null
            && $this->status->isLive()
            && $this->expires_at->isPast();
    }

    /** Customer may still decide: live, unexpired, and not already decided. */
    public function isDecidableByCustomer(): bool
    {
        return $this->status->isLive() && ! $this->hasExpired();
    }

    /** Business may pull it back only while the customer has not decided. */
    public function isWithdrawable(): bool
    {
        return $this->status->isLive() && ! $this->hasExpired();
    }

    /**
     * Extension point for Phase 7: an accepted quote is the input an Order
     * will be built from. Nothing here creates an order — that is a later
     * phase — but this is the single predicate it should ask.
     */
    public function isReadyForOrder(): bool
    {
        return $this->status === QuoteStatus::Accepted;
    }
}
