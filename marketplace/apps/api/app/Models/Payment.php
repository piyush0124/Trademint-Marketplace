<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One payment attempt against an order.
 *
 * `status`, `amount`, `paid_at` and the provider ids are absent from $fillable:
 * they are written by PaymentService in response to a verified provider event or
 * an authorised human confirmation. There is deliberately no code path by which a
 * request body can declare a payment successful.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'customer_id',
        'business_id',
        'provider',
        'currency',
        'payment_method',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_refunded' => 'decimal:2',
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'initiated_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            if (empty($payment->uuid)) {
                $payment->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Paid->value,
            PaymentStatus::PartiallyRefunded->value,
            PaymentStatus::Refunded->value,
        ]);
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Pending->value,
            PaymentStatus::Initiated->value,
            PaymentStatus::Processing->value,
        ]);
    }

    public function amountMinor(): int
    {
        return Money::toMinor($this->amount);
    }

    /** How much of this payment is still refundable. */
    public function refundableMinor(): int
    {
        return max(0, $this->amountMinor() - Money::toMinor($this->amount_refunded));
    }

    /** An initiated payment that was never confirmed within its window. */
    public function hasLapsed(): bool
    {
        return $this->status->isInFlight()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
