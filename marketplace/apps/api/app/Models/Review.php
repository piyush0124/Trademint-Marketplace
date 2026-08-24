<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A customer's review of a business, earned by a completed order.
 *
 * `rating`, `comment` and `order_id` are set once at creation. The business may add
 * a response; an admin may hide it. Neither can alter what the customer wrote, and
 * nothing is ever deleted — a negative review is a fact, not a defect.
 */
class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'business_id',
        'customer_id',
        'rating',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
            'business_responded_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Review $review): void {
            if (empty($review->uuid)) {
                $review->uuid = (string) Str::uuid();
            }
        });

        /**
         * The customer's own words are immutable once submitted. A business
         * response and admin moderation are separate columns precisely so that
         * adding them cannot touch the review itself.
         */
        static::updating(function (Review $review): void {
            $protected = array_intersect(array_keys($review->getDirty()), ['rating', 'comment', 'order_id', 'business_id', 'customer_id']);

            if ($protected !== []) {
                throw new \RuntimeException(sprintf(
                    'A submitted review cannot be altered (%s).', implode(', ', $protected)
                ));
            }
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Reviews are never deleted — hide them instead.');
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

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** Reviews that count towards the public rating. */
    public function scopePublicallyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [ReviewStatus::Published->value, ReviewStatus::Flagged->value]);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    public function hasResponse(): bool
    {
        return $this->business_response !== null;
    }
}
