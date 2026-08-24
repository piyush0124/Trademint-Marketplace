<?php

namespace App\Models;

use App\Enums\AiActionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A write action the AI has proposed, held until the user confirms it.
 *
 * This model is the whole confirmation guarantee. A write tool cannot run on the
 * turn the model selects it — it can only get as far as a row here. Execution needs
 * a separate authenticated request from the owning user, and at that point the
 * arguments are re-validated and the tool's own authorization runs again.
 *
 * `status` and `user_id` are not fillable. Status moves only through the methods
 * below, and the owner is the authenticated user, so neither can be forged by a
 * request body.
 */
class AiAction extends Model
{
    use HasFactory;

    protected $fillable = ['ai_conversation_id', 'tool', 'arguments', 'summary'];

    protected function casts(): array
    {
        return [
            'status' => AiActionStatus::class,
            'arguments' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiAction $action): void {
            if (empty($action->uuid)) {
                $action->uuid = (string) Str::uuid();
            }

            if ($action->status === null) {
                $action->status = AiActionStatus::PendingConfirmation;
            }

            if ($action->expires_at === null) {
                $action->expires_at = now()->addMinutes((int) config('ai.limits.action_ttl_minutes', 30));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AiActionStatus::PendingConfirmation->value);
    }

    /**
     * Still confirmable?
     *
     * Both halves matter. Status stops a replay of an already-executed action;
     * expiry stops a proposal from an abandoned conversation being confirmed days
     * later, by which time the user has forgotten the context they were agreeing to.
     */
    public function isConfirmable(): bool
    {
        return $this->status === AiActionStatus::PendingConfirmation
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasExpired(): bool
    {
        return $this->status === AiActionStatus::PendingConfirmation
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function markConfirmed(): void
    {
        $this->forceFill([
            'status' => AiActionStatus::Confirmed,
            'confirmed_at' => now(),
        ])->save();
    }

    /** @param  array<string, mixed>  $result */
    public function markExecuted(array $result): void
    {
        $this->forceFill([
            'status' => AiActionStatus::Executed,
            'result' => $result,
            'executed_at' => now(),
        ])->save();
    }

    public function markRejected(): void
    {
        $this->forceFill(['status' => AiActionStatus::Rejected])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => AiActionStatus::Failed,
            // Truncated because this string is shown to the user; an exception
            // message is a hint, not a stack trace to publish.
            'failure_reason' => Str::limit($reason, 240),
        ])->save();
    }

    public function markExpired(): void
    {
        $this->forceFill(['status' => AiActionStatus::Expired])->save();
    }
}
