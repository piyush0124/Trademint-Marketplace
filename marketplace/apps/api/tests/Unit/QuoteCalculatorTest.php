<?php

namespace Tests\Unit;

use App\Services\Quotes\QuoteCalculator;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The money maths, tested in isolation from HTTP and the database.
 *
 * These assertions are on exact decimal strings, not floats — if the arithmetic
 * ever drifts to floating point, these fail. Every scenario is expressed in a
 * different industry's terms to prove the same calculator serves all of them.
 */
class QuoteCalculatorTest extends TestCase
{
    private QuoteCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new QuoteCalculator;
    }

    private function line(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Line',
            'item_type' => 'custom',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- *
     | Line arithmetic
     * ---------------------------------------------------------------- */

    /** Manufacturing: 500 units x ₹800. */
    public function test_quantity_times_unit_price(): void
    {
        $result = $this->calculator->calculate([
            $this->line(['description' => 'Steel plates', 'item_type' => 'product', 'quantity' => 500, 'unit_price' => 800]),
        ]);

        $this->assertSame('400000.00', $result['items'][0]['subtotal']);
        $this->assertSame('400000.00', $result['totals']['subtotal']);
        $this->assertSame('400000.00', $result['totals']['total_amount']);
    }

    /** IT: a single project line priced as a whole. */
    public function test_single_project_line_with_tax(): void
    {
        $result = $this->calculator->calculate([
            $this->line(['description' => 'Website development', 'item_type' => 'project', 'quantity' => 1, 'unit_price' => 150000, 'tax_rate' => 18]),
        ]);

        $this->assertSame('150000.00', $result['totals']['subtotal']);
        $this->assertSame('27000.00', $result['totals']['tax_amount']);
        $this->assertSame('177000.00', $result['totals']['total_amount']);
    }

    /** Logistics: fractional quantities (2.5 tonnes) must not lose a paisa. */
    public function test_fractional_quantity_is_exact(): void
    {
        $result = $this->calculator->calculate([
            $this->line(['description' => 'Freight', 'item_type' => 'service', 'quantity' => 2.5, 'unit' => 'tonne', 'unit_price' => 10000]),
        ]);

        $this->assertSame('25000.00', $result['items'][0]['subtotal']);
        $this->assertSame('2.500', $result['items'][0]['quantity']);
    }

    /** Tax applies after the line discount, never before it. */
    public function test_line_discount_is_applied_before_tax(): void
    {
        $result = $this->calculator->calculate([
            $this->line(['unit_price' => 1000, 'quantity' => 1, 'discount_amount' => 100, 'tax_rate' => 10]),
        ]);

        $this->assertSame('1000.00', $result['items'][0]['subtotal']);
        $this->assertSame('100.00', $result['items'][0]['discount_amount']);
        // 10% of (1000 - 100), not of 1000.
        $this->assertSame('90.00', $result['items'][0]['tax_amount']);
        $this->assertSame('990.00', $result['items'][0]['total']);
    }

    /** Home services: a small job with an odd tax rate still rounds cleanly. */
    public function test_fractional_tax_rate_rounds_half_up(): void
    {
        $result = $this->calculator->calculate([
            $this->line(['description' => 'AC installation', 'quantity' => 1, 'unit_price' => 2500, 'tax_rate' => 18.5]),
        ]);

        // 2500 x 18.5% = 462.50 exactly.
        $this->assertSame('462.50', $result['items'][0]['tax_amount']);
        $this->assertSame('2962.50', $result['items'][0]['total']);
    }

    /**
     * A unit price is itself money, so it is normalised to paise *before*
     * being multiplied out: 33.335 -> 33.34, then x3 = 100.02.
     *
     * That ordering is deliberate. It means the figures printed on the quote
     * multiply to the total printed on the quote — a customer checking the
     * document by hand gets the same answer the backend did, which would not be
     * true if the sub-paisa price were kept internally.
     */
    public function test_unit_price_is_normalised_to_paise_before_multiplying(): void
    {
        $result = $this->calculator->calculate([
            $this->line(['quantity' => 3, 'unit_price' => '33.335']),
        ]);

        $this->assertSame('33.34', $result['items'][0]['unit_price']);
        $this->assertSame('100.02', $result['items'][0]['subtotal']);
    }

    /** Rounding on a derived figure (tax) is half-up at the paisa. */
    public function test_derived_amounts_round_half_up(): void
    {
        $result = $this->calculator->calculate([
            // 5% of 1000.50 = 50.025 -> 50.03
            $this->line(['quantity' => 1, 'unit_price' => '1000.50', 'tax_rate' => 5]),
        ]);

        $this->assertSame('50.03', $result['items'][0]['tax_amount']);
        $this->assertSame('1050.53', $result['items'][0]['total']);
    }

    /* ---------------------------------------------------------------- *
     | Quote totals
     * ---------------------------------------------------------------- */

    /** Marketing: a multi-line package with charges and a quote-level discount. */
    public function test_totals_combine_lines_charges_and_discounts(): void
    {
        $result = $this->calculator->calculate(
            [
                $this->line(['description' => 'Social media management', 'item_type' => 'service', 'quantity' => 3, 'unit_price' => 10000, 'tax_rate' => 18]),
                $this->line(['description' => 'Ad creatives', 'item_type' => 'service', 'quantity' => 10, 'unit_price' => 1500, 'tax_rate' => 18, 'discount_amount' => 1000]),
            ],
            ['delivery_fee' => 500, 'other_charges' => 250, 'discount_amount' => 2000]
        );

        // Line 1: 30000, tax 5400. Line 2: 15000 - 1000 = 14000, tax 2520.
        $this->assertSame('44000.00', $result['totals']['subtotal']);
        $this->assertSame('7920.00', $result['totals']['tax_amount']);
        $this->assertSame('500.00', $result['totals']['delivery_fee']);
        $this->assertSame('250.00', $result['totals']['other_charges']);
        // Every discount on the document: 1000 on the line + 2000 on the quote.
        $this->assertSame('3000.00', $result['totals']['discount_amount']);
        // 44000 + 7920 + 500 + 250 - 2000
        $this->assertSame('50670.00', $result['totals']['total_amount']);
        $this->assertSame('45000.00', $result['totals']['gross_subtotal']);
        $this->assertSame('1000.00', $result['totals']['item_discount_total']);
        $this->assertSame('2000.00', $result['totals']['quote_discount_total']);
    }

    /** Services and projects have no delivery — omitted charges are simply zero. */
    public function test_omitted_charges_default_to_zero(): void
    {
        $result = $this->calculator->calculate([$this->line(['unit_price' => 10000])]);

        $this->assertSame('0.00', $result['totals']['delivery_fee']);
        $this->assertSame('0.00', $result['totals']['other_charges']);
        $this->assertSame('0.00', $result['totals']['discount_amount']);
        $this->assertSame('10000.00', $result['totals']['total_amount']);
    }

    /* ---------------------------------------------------------------- *
     | Guards
     * ---------------------------------------------------------------- */

    public function test_a_quote_needs_at_least_one_line(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->calculate([]);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->calculate([$this->line(['quantity' => 0])]);
    }

    public function test_negative_unit_price_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->calculate([$this->line(['unit_price' => -100])]);
    }

    public function test_negative_tax_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->calculate([$this->line(['tax_rate' => -5])]);
    }

    /** A line discount cannot exceed the line, which would invert the sign. */
    public function test_line_discount_larger_than_the_line_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->calculate([$this->line(['unit_price' => 100, 'quantity' => 1, 'discount_amount' => 500])]);
    }

    /** A quote-level discount cannot produce a negative grand total. */
    public function test_quote_discount_larger_than_the_quote_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->calculate(
            [$this->line(['unit_price' => 1000])],
            ['discount_amount' => 5000]
        );
    }

    /* ---------------------------------------------------------------- *
     | Money primitive
     * ---------------------------------------------------------------- */

    public function test_money_parses_decimal_strings_without_floating_point_error(): void
    {
        $this->assertSame(10, Money::toMinor('0.1'));
        $this->assertSame(20, Money::toMinor('0.2'));
        // The classic float trap: 0.1 + 0.2 must be exactly 0.30.
        $this->assertSame('0.30', Money::fromMinor(Money::toMinor('0.1') + Money::toMinor('0.2')));
        $this->assertSame(123457, Money::toMinor('1234.565'));
        $this->assertSame(0, Money::toMinor(null));
        $this->assertSame(0, Money::toMinor(''));
    }

    public function test_money_handles_large_amounts(): void
    {
        // A ₹12,00,000 construction project.
        $this->assertSame('1200000.00', Money::fromMinor(Money::toMinor('1200000')));
    }

    public function test_money_rejects_non_numeric_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::toMinor('1,000');
    }
}
