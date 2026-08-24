<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A dispute raised on an order by either party (Phase 10 foundation).
 *
 * Once raised it is the admin's to move; neither party can resolve their own
 * dispute, and the original reason and description are never rewritten.
 */
class Dispute extends Model
{
    use HasFactory;

    /** Generic reasons — nothing industry-specific. */
    public const REASONS = [
        'not_delivered', 'quality', 'incomplete', 'late', 'billing', 'communication', 'cancellation', 'other',
    ];

    protected $fillable = [
        'uuid',
        'reference',
        'order_id',
        'customer_id',
        'business_id',
        'raised_by_party',
        'raised_by_user_id',
        'reason',
        'description',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Dispute $dispute): void {
            if (empty($dispute->uuid)) {
                $dispute->uuid = (string) Str::uuid();
            }
        });

        /** The complaint itself is a record of what was alleged, so it is frozen. */
        static::updating(function (Dispute $dispute): void {
            $protected = array_intersect(array_keys($dispute->getDirty()), ['reason', 'description', 'order_id', 'raised_by_party', 'customer_id', 'business_id']);

            if ($protected !== []) {
                throw new \RuntimeException(sprintf('A raised dispute cannot be rewritten (%s).', implode(', ', $protected)));
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [DisputeStatus::Open->value, DisputeStatus::UnderReview->value]);
    }

    /** Which side of the dispute a user is on, or null if neither. */
    public function partyFor(User $user): ?string
    {
        if ($user->id === $this->customer_id) {
            return 'customer';
        }

        $this->loadMissing('business');

        if ($this->business !== null && $this->business->user_id === $user->id) {
            return 'business';
        }

        return null;
    }
}
