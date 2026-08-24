<?php

namespace App\Models;

use App\Enums\MatchResponseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * The match record joining a Requirement to a Business, as produced by
 * MatchingService. Carries the score and the stored explanation, plus the
 * invitation lifecycle (invited -> viewed -> responded).
 *
 * This model holds no scoring logic — it is the persisted outcome of a
 * scoring run, and the engine is free to change without changing this shape.
 */
class RequirementMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'requirement_id',
        'business_id',
        'match_score',
        'match_reason',
        'invited_at',
        'viewed_at',
        'response_status',
        'responded_at',
        'response_note',
        'question',
        'question_asked_at',
    ];

    protected function casts(): array
    {
        return [
            'match_score' => 'decimal:2',
            'match_reason' => 'array',
            'invited_at' => 'datetime',
            'viewed_at' => 'datetime',
            'response_status' => MatchResponseStatus::class,
            'responded_at' => 'datetime',
            'question_asked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RequirementMatch $match): void {
            if (empty($match->uuid)) {
                $match->uuid = (string) Str::uuid();
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

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The Phase 5 enquiry opened off this match, if the conversation has
     * actually started. HasOne rather than HasMany: one match produces at most
     * one customer/business conversation.
     */
    public function enquiry(): HasOne
    {
        return $this->hasOne(Enquiry::class, 'match_id');
    }

    /** Ranked best-first — the order both the customer and the engine think in. */
    public function scopeRanked(Builder $query): Builder
    {
        return $query->orderByDesc('match_score')->orderBy('id');
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    /** Whole percentage points, for display. Stored value is decimal(5,2). */
    public function scorePercentage(): int
    {
        return (int) round((float) $this->match_score);
    }

    public function hasResponded(): bool
    {
        return $this->response_status->isFinal();
    }

    /**
     * A business may still act on the invitation. Requires that it hasn't
     * already answered and that the requirement is still open — a cancelled
     * or closed requirement is not something to accept.
     */
    public function isActionableByBusiness(): bool
    {
        return ! $this->hasResponded()
            && $this->requirement !== null
            && in_array($this->requirement->status, Requirement::PUBLICLY_VISIBLE_STATUSES, true);
    }

    /** The stored "why matched" lines, safe for display. */
    public function reasons(): array
    {
        $reasons = $this->match_reason['reasons'] ?? [];

        return is_array($reasons) ? array_values(array_filter($reasons, 'is_string')) : [];
    }

    public function breakdown(): array
    {
        $breakdown = $this->match_reason['breakdown'] ?? [];

        return is_array($breakdown) ? $breakdown : [];
    }

    /** Snapshot of the products/services that made this business relevant. */
    public function matchedCapabilities(): array
    {
        $capabilities = $this->match_reason['capabilities'] ?? [];

        return is_array($capabilities) ? array_values($capabilities) : [];
    }
}
