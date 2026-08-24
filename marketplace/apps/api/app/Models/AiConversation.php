<?php

namespace App\Models;

use App\Enums\AiConversationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One AI thread, owned by exactly one user.
 *
 * `user_id` and `type` are absent from `$fillable`: ownership comes from the
 * authenticated session and the type is validated against AiConversationType by the
 * service, so neither can be set by a request body. That is what stops "open an
 * admin copilot as a customer" being a JSON field away.
 */
class AiConversation extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'context'];

    protected function casts(): array
    {
        return [
            'type' => AiConversationType::class,
            'context' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiConversation $conversation): void {
            if (empty($conversation->uuid)) {
                $conversation->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Oldest first — a transcript reads forwards. */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AiAction::class)->latest('id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * The trailing window of turns replayed as model context.
     *
     * Capped rather than unbounded for three reasons: cost scales with every token
     * resent, long contexts measurably degrade instruction-following, and an
     * assistant answering "which quotes are pending?" does not need last week's
     * chat. Tool-result messages are excluded — they were already summarised into
     * the assistant reply that followed them, and replaying raw tool JSON is the
     * single largest avoidable token cost in a thread like this.
     *
     * @return Collection<int, AiMessage>
     */
    public function contextWindow(?int $limit = null): Collection
    {
        $limit ??= (int) config('ai.limits.context_messages', 12);

        return $this->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();
    }

    public function touchLastMessage(): void
    {
        $this->forceFill(['last_message_at' => now()])->save();
    }
}
