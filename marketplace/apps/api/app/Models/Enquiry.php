<?php

namespace App\Models;

use App\Enums\EnquiryStatus;
use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The conversation between one customer and one business about a requirement,
 * created off the back of a match. Quoting is gated on it: a business may only
 * quote an enquiry it has accepted.
 *
 * Holds no workflow logic — transitions live in EnquiryService — and no
 * industry-specific fields.
 */
class Enquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'requirement_id',
        'customer_id',
        'business_id',
        'match_id',
        'status',
        'initiated_by',
        'customer_message',
        'business_message',
        'accepted_at',
        'declined_at',
        'closed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnquiryStatus::class,
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Enquiry $enquiry): void {
            if (empty($enquiry->uuid)) {
                $enquiry->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
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

    public function match(): BelongsTo
    {
        return $this->belongsTo(RequirementMatch::class, 'match_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** The quote the customer accepted, if any — the hand-off point for Phase 7 orders. */
    public function acceptedQuote(): ?Quote
    {
        return $this->quotes()->where('status', QuoteStatus::Accepted)->first();
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    public function isPending(): bool
    {
        return $this->status === EnquiryStatus::Pending;
    }

    public function isAccepted(): bool
    {
        return $this->status === EnquiryStatus::Accepted;
    }

    /** Only an accepted enquiry may be quoted — enforced in QuoteService too. */
    public function allowsQuoting(): bool
    {
        return $this->isAccepted();
    }

    /** The business still has a decision to make. */
    public function awaitsBusinessResponse(): bool
    {
        return $this->isPending();
    }
}
