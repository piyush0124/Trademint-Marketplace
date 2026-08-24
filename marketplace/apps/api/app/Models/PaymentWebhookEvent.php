<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of a provider webhook, kept whether or not it was acted on.
 *
 * This table *is* the idempotency mechanism: the unique (provider, event_id) index
 * means a redelivered event cannot be processed twice, and a rejected one leaves
 * evidence of why. Payloads are retained for dispute investigation.
 */
class PaymentWebhookEvent extends Model
{
    use HasFactory;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    /** Valid, but not an event we act on (e.g. an informational notice). */
    public const STATUS_IGNORED = 'ignored';

    /** Refused: bad signature, amount/currency/order mismatch, unknown payment. */
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payment_id',
        'signature_valid',
        'status',
        'error',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
