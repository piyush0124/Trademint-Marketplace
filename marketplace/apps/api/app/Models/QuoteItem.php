<?php

namespace App\Models;

use App\Enums\QuoteItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line of a quote.
 *
 * The derived money columns (subtotal, tax_amount, total) are absent from
 * $fillable because QuoteService computes them; a client supplies only the
 * inputs (quantity, unit price, tax rate, discount).
 */
class QuoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'sort_order',
        'description',
        'item_type',
        'reference_type',
        'reference_id',
        'quantity',
        'unit',
        'unit_price',
        'tax_rate',
        'discount_amount',
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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
