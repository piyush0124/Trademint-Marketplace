<?php

namespace App\Models;

use App\Enums\NegotiationParty;
use App\Enums\NegotiationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The negotiation over one quote: its state, whose turn it is, and its timeline.
 *
 * All transition *rules* live here as predicates so both the service and the
 * policy ask the same questions, and the API can tell the frontend exactly which
 * actions are available without duplicating the logic.
 */
class Negotiation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'quote_id',
        'requirement_id',
        'customer_id',
        'business_id',
        'status',
        'initiated_by',
        'awaiting_party',
        'round',
        'current_revision_id',
        'last_activity_at',
        'accepted_at',
        'rejected_at',
        'withdrawn_at',
        'closed_at',
        'outcome_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => NegotiationStatus::class,
            'initiated_by' => NegotiationParty::class,
            'awaiting_party' => NegotiationParty::class,
            'round' => 'integer',
            'last_activity_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Negotiation $negotiation): void {
            if (empty($negotiation->uuid)) {
                $negotiation->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
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

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class, 'current_revision_id');
    }

    /** The whole immutable offer history, oldest first — the commercial record. */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuoteRevision::class, 'quote_id', 'quote_id')->orderBy('revision_number');
    }

    /** The timeline, oldest first. */
    public function messages(): HasMany
    {
        return $this->hasMany(NegotiationMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [NegotiationStatus::Open->value, NegotiationStatus::Countered->value]);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Whether the given party may act right now.
     *
     * Two conditions, both necessary: the negotiation must still be live, and it
     * must actually be that party's turn. This is the single predicate behind
     * every Phase 6 rule, including the explicit "accepted → counter offer must
     * not be allowed" requirement.
     */
    public function awaits(NegotiationParty $party): bool
    {
        return $this->isActive() && $this->awaiting_party === $party;
    }

    /** The live offer has lapsed — nothing can be accepted against it. */
    public function hasExpired(): bool
    {
        if ($this->status === NegotiationStatus::Expired) {
            return true;
        }

        $this->loadMissing('currentRevision');

        return $this->isActive()
            && $this->currentRevision !== null
            && $this->currentRevision->hasExpired();
    }

    /** The party a given user belongs to, or null if they are neither. */
    public function partyFor(User $user): ?NegotiationParty
    {
        if ($user->id === $this->customer_id) {
            return NegotiationParty::Customer;
        }

        $this->loadMissing('business');

        if ($this->business !== null && $this->business->user_id === $user->id) {
            return NegotiationParty::Business;
        }

        return null;
    }
}
