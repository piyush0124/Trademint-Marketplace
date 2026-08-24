<?php

namespace Tests\Unit;

use App\Services\Ai\Extraction\AmountNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Indian amount parsing.
 *
 * A unit test rather than a feature test, and exhaustive rather than representative,
 * because this is the one place in the AI layer where a quiet mistake is measured in
 * lakhs. "15 lakh" read as 15 would understate a budget by five orders of magnitude and
 * match the requirement to entirely the wrong suppliers — so every spelling people
 * actually type gets pinned here.
 */
class AmountNormaliserTest extends TestCase
{
    private AmountNormaliser $normaliser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normaliser = new AmountNormaliser;
    }

    /** @return array<string, array{0: mixed, 1: int|null}> */
    public static function amounts(): array
    {
        return [
            // Plain numbers, including Indian and Western grouping.
            'integer' => [500000, 500000],
            'numeric string' => ['500000', 500000],
            'indian grouping' => ['5,00,000', 500000],
            'western grouping' => ['500,000', 500000],
            'spaced grouping' => ['5 00 000', 500000],

            // Lakh, in every spelling seen in the wild.
            'lakh' => ['5 lakh', 500000],
            'lakhs' => ['5 lakhs', 500000],
            'lac' => ['5 lac', 500000],
            'lacs' => ['5 lacs', 500000],
            'laakh' => ['5 laakh', 500000],
            'bare L' => ['5L', 500000],
            'lowercase l' => ['5l', 500000],
            'decimal lakh' => ['2.5 lakh', 250000],
            'fifteen lakh' => ['15 lakh', 1500000],
            'the brief example' => ['around 15 lakh', 1500000],

            // Crore.
            'crore' => ['1 crore', 10000000],
            'decimal crore' => ['1.5 crore', 15000000],
            'cr abbreviation' => ['1.5cr', 15000000],
            'crores plural' => ['2 crores', 20000000],

            // Thousand.
            'k suffix' => ['15k', 15000],
            'thousand word' => ['15 thousand', 15000],
            'hazaar' => ['50 hazaar', 50000],

            // Currency noise must not change the value.
            'rupee sign' => ['₹5 lakh', 500000],
            'rs prefix' => ['Rs. 2.5 Lakh', 250000],
            'rs no dot' => ['rs 500000', 500000],
            'inr prefix' => ['INR 12,00,000', 1200000],
            'trailing slash' => ['500000/-', 500000],

            // Hedge words carry no value.
            'approx' => ['approx 3 lakh', 300000],
            'about' => ['about 3lakh', 300000],
            'upto' => ['upto 10 lakh', 1000000],
            'budget prefix' => ['budget 8 lakh', 800000],

            // Spelled out, English and transliterated Hindi.
            'five lakh' => ['five lakh', 500000],
            'two crore' => ['two crore', 20000000],
            'paanch lakh' => ['paanch lakh', 500000],
            'do crore' => ['do crore', 20000000],
            'fifteen lakh words' => ['fifteen lakh', 1500000],
            'two and a half lakh' => ['two and a half lakh', 250000],

            // Nothing usable.
            'null' => [null, null],
            'empty' => ['', null],
            'no digits' => ['negotiable', null],
            'not a number' => ['as per market rate', null],
            'negative' => [-5000, null],
            'boolean' => [true, null],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_normalises_indian_amounts(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, $this->normaliser->normalise($input));
    }

    public function test_a_single_figure_is_read_as_a_ceiling_not_a_floor(): void
    {
        // "Budget 15 lakh" means "up to". Treating it as a minimum would exclude every
        // supplier who could do the job cheaper, which is the opposite of useful.
        $this->assertSame(['min' => null, 'max' => 1500000], $this->normaliser->normaliseRange('15 lakh'));
    }

    /**
     * The scale word has to carry backwards across the range.
     *
     * "5-10 lakh" means 500000–1000000. A parser that reads the left side in isolation
     * gets 5, and the requirement then looks like it has a five-rupee floor.
     */
    public function test_a_range_inherits_the_scale_word_from_the_upper_bound(): void
    {
        $this->assertSame(['min' => 500000, 'max' => 1000000], $this->normaliser->normaliseRange('5-10 lakh'));
        $this->assertSame(['min' => 500000, 'max' => 1000000], $this->normaliser->normaliseRange('5 to 10 lakh'));
        $this->assertSame(['min' => 500000, 'max' => 1000000], $this->normaliser->normaliseRange('between 5 and 10 lakh'));
        $this->assertSame(['min' => 500000, 'max' => 1000000], $this->normaliser->normaliseRange('₹5 – ₹10 lakh'));
    }

    public function test_a_range_with_explicit_scales_on_both_sides_is_respected(): void
    {
        $this->assertSame(['min' => 500000, 'max' => 2000000], $this->normaliser->normaliseRange('5 lakh to 20 lakh'));
        $this->assertSame(['min' => 1500000, 'max' => 30000000], $this->normaliser->normaliseRange('15 lakh to 3 crore'));
    }

    public function test_a_reversed_range_is_corrected_rather_than_stored_backwards(): void
    {
        $this->assertSame(['min' => 500000, 'max' => 1000000], $this->normaliser->normaliseRange('10 lakh to 5 lakh'));
    }

    public function test_an_unparseable_range_yields_nulls_rather_than_zeros(): void
    {
        // Null is honest. Zero would look like a real budget and break matching.
        $this->assertSame(['min' => null, 'max' => null], $this->normaliser->normaliseRange('negotiable'));
        $this->assertSame(['min' => null, 'max' => null], $this->normaliser->normaliseRange(null));
    }
}
