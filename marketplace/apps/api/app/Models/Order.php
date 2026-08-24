<?php

namespace App\Models;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * An order formed from an accepted quote revision.
 *
 * Money and status are absent from $fillable: totals are copied from the accepted
 * revision by OrderService, statuses move only through its guarded transitions,
 * and the payment position is written only by the payment layer. A client can
 * therefore never price, progress or pay an order by sending fields.
 */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'quote_id',
        'quote_revision_id',
        'requirement_id',
        'customer_id',
        'business_id',
        'currency',
        'expected_delivery_date',
        'expected_completion_date',
        'customer_notes',
        'business_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'fulfilment_status' => FulfilmentStatus::class,
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_refunded' => 'decimal:2',
            'expected_delivery_date' => 'date',
            'expected_completion_date' => 'date',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
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

    /** The exact immutable offer this order was formed from. */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class, 'quote_revision_id');
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
        return $this->hasMany(OrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Append-only lifecycle audit, oldest first. */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    /** Payment attempts against this order (Phase 8). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * The single review this order can earn (Phase 9). A HasOne rather than HasMany
     * because `reviews.order_id` is unique — the schema is what makes "one review
     * per order" true, and the relation just reflects it.
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /** Disputes raised on this order (Phase 10), newest first. */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class)->latest('id');
    }

    /**
     * The dispute currently blocking this order, if any.
     *
     * Reads from the loaded collection when the caller eager-loaded `disputes`, so
     * a list of orders costs one query rather than one per row.
     */
    public function activeDispute(): ?Dispute
    {
        if ($this->relationLoaded('disputes')) {
            return $this->disputes->first(fn (Dispute $dispute) => $dispute->status->isActive());
        }

        return $this->disputes()->active()->first();
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value]);
    }

    /** Outstanding balance, in the order's own currency. */
    public function amountDue(): string
    {
        $due = (float) $this->total_amount - (float) $this->amount_paid;

        return number_format(max(0, $due), 2, '.', '');
    }

    public function isPaid(): bool
    {
        return $this->payment_status->isSettled();
    }

    /**
     * A customer may cancel only before the business has started work. After
     * that it is a dispute, not a cancellation — which protects the business from
     * losing committed effort.
     */
    public function isCancellableByCustomer(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isCancellableByBusiness(): bool
    {
        return in_array($this->status, [OrderStatus::Pending, OrderStatus::Confirmed], true);
    }

    /** The customer signs off completion once the work has been delivered. */
    public function isCompletableByCustomer(): bool
    {
        return in_array($this->status, [OrderStatus::Delivered, OrderStatus::Ready, OrderStatus::Dispatched], true);
    }

    /** Phase 8 hook: a payment may only be taken against a live, unpaid order. */
    public function isPayable(): bool
    {
        return ! $this->status->isFinal()
            && ! in_array($this->payment_status, [OrderPaymentStatus::Paid, OrderPaymentStatus::Refunded], true)
            && (float) $this->amountDue() > 0;
    }
}
