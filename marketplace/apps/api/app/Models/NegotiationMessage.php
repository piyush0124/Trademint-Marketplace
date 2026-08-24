<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in a negotiation timeline: an offer, a decision, or a
 * plain message. Never edited or deleted, so the conversation reads exactly as
 * it happened.
 */
class NegotiationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'negotiation_id',
        'quote_revision_id',
        'user_id',
        'author_party',
        'entry_type',
        'body',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Negotiation timeline entries are append-only.');
        });
    }

    public function negotiation(): BelongsTo
    {
        return $this->belongsTo(Negotiation::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class, 'quote_revision_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
