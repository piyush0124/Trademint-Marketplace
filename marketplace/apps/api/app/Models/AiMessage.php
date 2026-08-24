<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single turn in a thread.
 *
 * Messages are immutable once written. A transcript that can be edited after the
 * fact is worthless for working out what the assistant actually told someone, and
 * these threads inform real commercial decisions.
 */
class AiMessage extends Model
{
    use HasFactory;

    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'metadata',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Append-only at the model level, so a future controller cannot quietly
        // rewrite history through a mass update.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class, 'ai_message_id');
    }

    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    /**
     * Which tools produced the facts in this reply.
     *
     * Surfaced to the user so an answer can be traced to the data behind it: an
     * assistant that says "you have 3 pending quotes" should be able to show that it
     * asked the quotes endpoint rather than guessed.
     *
     * @return array<int, string>
     */
    public function groundingTools(): array
    {
        return array_values(array_filter((array) ($this->metadata['tools_used'] ?? [])));
    }
}
