<?php

namespace App\Models;

use App\Enums\RequirementStatus;
use App\Enums\RequirementUrgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Requirement extends Model
{
    use HasFactory;

    /**
     * Statuses under which a Requirement is visible to businesses browsing
     * the marketplace. Per the Phase 3 spec, "publicly available" means it
     * has left draft but hasn't been closed or cancelled yet. Matching and
     * quote logic (later phases) will read this same list; nothing here
     * decides *which* businesses see it, only *that* it's visible at all.
     */
    public const PUBLICLY_VISIBLE_STATUSES = [
        RequirementStatus::Published,
        RequirementStatus::Matching,
        RequirementStatus::ReceivingQuotes,
    ];

    protected $fillable = [
        'uuid',
        'customer_id',
        'category_id',
        'location_id',
        'title',
        'description',
        'quantity',
        'unit',
        'budget_min',
        'budget_max',
        'required_date',
        'urgency',
        'status',
        'published_at',
        'closed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'required_date' => 'date',
            'urgency' => RequirementUrgency::class,
            'status' => RequirementStatus::class,
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Requirement $requirement): void {
            if (empty($requirement->uuid)) {
                $requirement->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Businesses the matching engine invited to this requirement. */
    public function matches(): HasMany
    {
        return $this->hasMany(RequirementMatch::class);
    }

    /** Customer <-> business conversations opened against this requirement (Phase 5). */
    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            array_map(fn (RequirementStatus $status) => $status->value, self::PUBLICLY_VISIBLE_STATUSES)
        );
    }

    public function isDraft(): bool
    {
        return $this->status === RequirementStatus::Draft;
    }

    /** A requirement can only be edited by its customer while still a draft. */
    public function isEditableByCustomer(): bool
    {
        return $this->status === RequirementStatus::Draft;
    }

    /**
     * Matching only ever runs on a requirement that has actually been made
     * available to businesses — never a draft, never one already closed or
     * cancelled. Same list the marketplace browse uses, deliberately.
     */
    public function isMatchable(): bool
    {
        return in_array($this->status, self::PUBLICLY_VISIBLE_STATUSES, true);
    }

    /** Anything short of closed/cancelled can still be cancelled by the customer. */
    public function isCancellable(): bool
    {
        return ! in_array($this->status, [RequirementStatus::Closed, RequirementStatus::Cancelled], true);
    }
}
