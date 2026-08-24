<?php

namespace App\Models;

use App\Enums\QuoteItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen line from one revision. Same generic shape as QuoteItem — a
 * description, a type, a quantity and a price — so it serves every industry,
 * and immutable for the same reason its parent revision is.
 */
class QuoteRevisionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_revision_id',
        'sort_order',
        'description',
        'item_type',
        'reference_type',
        'reference_id',
        'quantity',
        'unit',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'discount_amount',
        'subtotal',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => QuoteItemType::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Revision items are immutable — create a new revision instead.');
        });
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class, 'quote_revision_id');
    }
}
