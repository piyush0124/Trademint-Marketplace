<?php

namespace Database\Factories;

use App\Enums\QuoteItemType;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    protected $model = QuoteItem::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'sort_order' => 0,
            'description' => fake()->words(4, true),
            'item_type' => QuoteItemType::Custom,
            'quantity' => 1,
            'unit' => null,
            'unit_price' => 1000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'subtotal' => 1000,
            'total' => 1000,
        ];
    }
}
