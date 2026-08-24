<?php

namespace App\Services\Quotes;

use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * The single place quote money is worked out.
 *
 * Contract: the client supplies *inputs only* — quantity, unit price, tax
 * rate, per-line discount, plus quote-level delivery fee, other charges and
 * discount. Every derived figure (line subtotal, line tax, line total, quote
 * subtotal, tax, grand total) is computed here and overwrites anything a
 * client may have sent. A request cannot influence a total except through the
 * inputs, which are individually validated and non-negative.
 *
 * Arithmetic runs entirely in integer minor units (see App\Support\Money), so
 * totals are exact and reproducible rather than floating-point approximations.
 *
 * Per line:
 *   subtotal = quantity x unit_price          (rounded half-up to 2dp)
 *   taxable  = subtotal - discount_amount
 *   tax      = taxable x tax_rate%
 *   total    = taxable + tax
 *
 * Per quote:
 *   subtotal = sum(line taxable)              — net of line discounts
 *   tax      = sum(line tax)
 *   total    = subtotal + tax + delivery_fee + other_charges - discount_amount
 *
 * The quote's `discount_amount` column stores the total of every discount
 * applied (line-level plus quote-level) so the document is self-describing,
 * while `subtotal` is already net of line discounts. `totals()` returns both
 * the stored figures and the components, so nothing has to be re-derived.
 *
 * Nothing here is industry-aware: a line is a description, a quantity and a
 * price, whether it is 500 units of steel or one website build.
 */
class QuoteCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $items  validated line inputs
     * @param  array<string, mixed>  $charges  validated quote-level inputs
     * @return array{items: array<int, array<string, string>>, totals: array<string, string>}
     */
    public function calculate(array $items, array $charges = []): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => ['A quote needs at least one line item.'],
            ]);
        }

        $lines = [];
        $subtotalMinor = 0;      // net of line discounts
        $grossMinor = 0;         // before line discounts
        $taxMinor = 0;
        $lineDiscountMinor = 0;

        foreach ($items as $index => $item) {
            $line = $this->calculateLine($item, $index);

            $lines[] = $line['stored'];
            $grossMinor += $line['gross_minor'];
            $lineDiscountMinor += $line['discount_minor'];
            $subtotalMinor += $line['taxable_minor'];
            $taxMinor += $line['tax_minor'];
        }

        $deliveryMinor = $this->nonNegative($charges['delivery_fee'] ?? 0, 'delivery_fee');
        $otherMinor = $this->nonNegative($charges['other_charges'] ?? 0, 'other_charges');
        $quoteDiscountMinor = $this->nonNegative($charges['discount_amount'] ?? 0, 'discount_amount');

        $beforeQuoteDiscount = $subtotalMinor + $taxMinor + $deliveryMinor + $otherMinor;

        // A quote-level discount may not exceed what is actually owed —
        // otherwise a crafted request could produce a negative grand total.
        if ($quoteDiscountMinor > $beforeQuoteDiscount) {
            throw ValidationException::withMessages([
                'discount_amount' => ['The discount cannot be greater than the quote total.'],
            ]);
        }

        $totalMinor = $beforeQuoteDiscount - $quoteDiscountMinor;

        return [
            'items' => $lines,
            'totals' => [
                'subtotal' => Money::fromMinor($subtotalMinor),
                'tax_amount' => Money::fromMinor($taxMinor),
                'delivery_fee' => Money::fromMinor($deliveryMinor),
                'other_charges' => Money::fromMinor($otherMinor),
                // Every discount on the document, so the customer can see the
                // full saving in one figure.
                'discount_amount' => Money::fromMinor($lineDiscountMinor + $quoteDiscountMinor),
                'total_amount' => Money::fromMinor($totalMinor),
                // Components, useful for the preview and for auditing.
                'gross_subtotal' => Money::fromMinor($grossMinor),
                'item_discount_total' => Money::fromMinor($lineDiscountMinor),
                'quote_discount_total' => Money::fromMinor($quoteDiscountMinor),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{stored: array<string, string>, gross_minor: int, discount_minor: int, taxable_minor: int, tax_minor: int}
     */
    private function calculateLine(array $item, int $index): array
    {
        $quantityMinor = Money::toMinor($item['quantity'] ?? 1, Money::QUANTITY_SCALE);

        if ($quantityMinor <= 0) {
            throw ValidationException::withMessages([
                "items.{$index}.quantity" => ['Quantity must be greater than zero.'],
            ]);
        }

        $unitPriceMinor = $this->nonNegative($item['unit_price'] ?? 0, "items.{$index}.unit_price");
        $taxRateMinor = $this->nonNegative($item['tax_rate'] ?? 0, "items.{$index}.tax_rate", Money::RATE_SCALE);
        $discountMinor = $this->nonNegative($item['discount_amount'] ?? 0, "items.{$index}.discount_amount");

        $grossMinor = Money::multiplyByQuantity($unitPriceMinor, $item['quantity'] ?? 1);

        // A line discount cannot exceed the line itself.
        if ($discountMinor > $grossMinor) {
            throw ValidationException::withMessages([
                "items.{$index}.discount_amount" => ['The discount cannot be greater than the line total.'],
            ]);
        }

        $taxableMinor = $grossMinor - $discountMinor;
        $taxMinor = Money::percentageOf($taxableMinor, $item['tax_rate'] ?? 0);
        $totalMinor = $taxableMinor + $taxMinor;

        return [
            'stored' => [
                'subtotal' => Money::fromMinor($grossMinor),
                'discount_amount' => Money::fromMinor($discountMinor),
                'tax_amount' => Money::fromMinor($taxMinor),
                'total' => Money::fromMinor($totalMinor),
                'unit_price' => Money::fromMinor($unitPriceMinor),
                'tax_rate' => Money::fromMinor($taxRateMinor, Money::RATE_SCALE),
                'quantity' => Money::fromMinor($quantityMinor, Money::QUANTITY_SCALE),
            ],
            'gross_minor' => $grossMinor,
            'discount_minor' => $discountMinor,
            'taxable_minor' => $taxableMinor,
            'tax_minor' => $taxMinor,
        ];
    }

    /**
     * Parses a monetary/rate input and refuses negatives. Validation rules
     * already cover this at the request layer; repeating it here means the
     * calculator is safe no matter who calls it.
     */
    private function nonNegative(mixed $value, string $field, int $scale = Money::SCALE): int
    {
        $minor = Money::toMinor(is_scalar($value) || $value === null ? $value : 0, $scale);

        if ($minor < 0) {
            throw ValidationException::withMessages([
                $field => ['This value cannot be negative.'],
            ]);
        }

        return $minor;
    }
}
