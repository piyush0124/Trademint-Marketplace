<?php

namespace App\Models;

use App\Enums\NegotiationParty;
use App\Enums\QuoteItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One immutable commercial offer within a quote's history.
 *
 * Immutability is enforced in the model itself: once saved, an attempt to change
 * any commercial column throws. Only the two bookkeeping columns (`is_current`,
 * `superseded_at`) may be touched afterwards. That guarantee is what makes the
 * negotiation history trustworthy — and it is asserted by test, not just
 * documented here.
 */
class QuoteRevision extends Model
{
    use HasFactory;

    /** Columns that may legitimately change after a revision is written. */
    private const MUTABLE_AFTER_CREATION = ['is_current', 'superseded_at', 'updated_at'];

    protected $fillable = [
        'uuid',
        'quote_id',
        'revision_number',
        'authored_by',
        'authored_by_user_id',
        'revision_type',
        'is_final_offer',
        'subtotal',
        'tax_amount',
        'delivery_fee',
        'discount_amount',
        'other_charges',
        'total_amount',
        'currency',
        'validity_date',
        'estimated_delivery_date',
        'estimated_completion_date',
        'expires_at',
        'payment_terms',
        'warranty_terms',
        'cancellation_terms',
        'notes',
        'message',
        'is_current',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'authored_by' => NegotiationParty::class,
            'is_final_offer' => 'boolean',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'validity_date' => 'date',
            'estimated_delivery_date' => 'date',
            'estimated_completion_date' => 'date',
            'expires_at' => 'datetime',
            'is_current' => 'boolean',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (QuoteRevision $revision): void {
            if (empty($revision->uuid)) {
                $revision->uuid = (string) Str::uuid();
            }
        });

        /**
         * The immutability guard. A revision is a historical record of what was
         * offered; rewriting it would falsify the negotiation. Anything other
         * than the bookkeeping columns is refused outright rather than silently
         * ignored, so a programming mistake surfaces immediately.
         */
        static::updating(function (QuoteRevision $revision): void {
            $illegal = array_diff(array_keys($revision->getDirty()), self::MUTABLE_AFTER_CREATION);

            if ($illegal !== []) {
                throw new \RuntimeException(sprintf(
                    'Quote revisions are immutable; attempted to modify: %s. Create a new revision instead.',
                    implode(', ', $illegal)
                ));
            }
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Quote revisions cannot be deleted — commercial history must be preserved.');
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteRevisionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /** The party whose turn it is to respond to this offer. */
    public function awaitingParty(): NegotiationParty
    {
        return $this->authored_by->counterparty();
    }

    /** Was this offer made by the given party? */
    public function authoredBy(NegotiationParty $party): bool
    {
        return $this->authored_by === $party;
    }

    /**
     * Expiry is computed, never read from a status, so a lapsed offer cannot be
     * accepted regardless of what any cache or frontend believes.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Human summary for the timeline, independent of industry. */
    public function summary(): string
    {
        return sprintf('%s %s offer: %s %s',
            $this->authored_by->label(),
            $this->revision_type === 'initial' ? 'opening' : 'counter',
            $this->currency,
            $this->total_amount,
        );
    }

    /**
     * Snapshot the line inputs in the shape QuoteCalculator accepts, so a
     * counter offer can start from the current offer without re-deriving money.
     *
     * @return array<int, array<string, mixed>>
     */
    public function itemInputs(): array
    {
        return $this->items->map(fn (QuoteRevisionItem $item) => [
            'description' => $item->description,
            'item_type' => $item->item_type instanceof QuoteItemType ? $item->item_type->value : (string) $item->item_type,
            'reference_id' => $item->reference_id,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => $item->unit_price,
            'tax_rate' => $item->tax_rate,
            'discount_amount' => $item->discount_amount,
        ])->all();
    }
}
