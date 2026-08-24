<?php

namespace App\Models;

use App\Enums\AiRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One provider call: what it cost, how long it took, whether it worked.
 *
 * This is the only table the daily quota is counted from, which matters — a quota
 * held in the cache resets when the cache is flushed, and "restart the container to
 * get more AI" is not a limit.
 *
 * Failed calls are recorded too, and deliberately still count against the quota.
 * Otherwise a user whose requests all fail can retry without bound, which is
 * exactly the load you least want when a provider is already struggling.
 */
class AiRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actor_role',
        'feature',
        'provider',
        'model',
        'status',
        'latency_ms',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => AiRequestStatus::class,
            'latency_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiRequest $request): void {
            if (empty($request->uuid)) {
                $request->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->startOfDay());
    }

    /**
     * Calls that consumed provider capacity.
     *
     * A request rejected locally — AI disabled, quota already spent — never reached
     * the provider, so counting it against the quota would double-charge the user
     * for our own refusal.
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            AiRequestStatus::Disabled->value,
            AiRequestStatus::QuotaExceeded->value,
        ]);
    }
}
