<?php

namespace App\Models;

use App\Enums\RefundStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A refund against a settled payment, full or partial.
 *
 * Recorded as its own row rather than by decrementing the payment, so the history
 * of what was returned, when, why and by whom survives.
 */
class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'payment_id',
        'order_id',
        'currency',
        'reason',
        'requested_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => RefundStatus::class,
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Refund $refund): void {
            if (empty($refund->uuid)) {
                $refund->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function amountMinor(): int
    {
        return Money::toMinor($this->amount);
    }
}
