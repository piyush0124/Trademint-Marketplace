<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Minor-unit money arithmetic for quote calculations.
 *
 * Every amount is handled as an integer number of minor units (paise for
 * INR, cents for USD, and so on) so no monetary value is ever the result of
 * binary floating point. `0.1 + 0.2` problems and drifting totals are
 * impossible by construction, and rounding happens exactly once per step,
 * explicitly, half-up — the convention invoices are expected to follow.
 *
 * Input parsing is done on the *string* representation rather than casting to
 * float, so "1234.565" rounds deterministically instead of depending on how
 * the value happened to be stored in a double.
 *
 * Currency-agnostic on purpose: the caller decides the currency and stores it
 * alongside the amounts. Nothing here assumes INR beyond the default scale of
 * 2 decimal places, which is itself configurable per call.
 */
final class Money
{
    /** Decimal places for a monetary amount (paise/cents). */
    public const SCALE = 2;

    /** Decimal places allowed on a quantity — supports 2.5 tonnes, 0.75 hours. */
    public const QUANTITY_SCALE = 3;

    /** Decimal places on a percentage rate, e.g. an 18.00% tax rate. */
    public const RATE_SCALE = 2;

    /**
     * Parse a decimal amount into integer minor units, rounding half-up at
     * the given scale. Accepts int, float or numeric string; null and empty
     * string are treated as zero so optional charge fields can be omitted.
     */
    public static function toMinor(int|float|string|null $amount, int $scale = self::SCALE): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $value = is_string($amount) ? trim($amount) : self::floatToString($amount);

        if (! preg_match('/^[+-]?\d*(\.\d+)?$/', $value) || $value === '' || $value === '+' || $value === '-') {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid decimal amount.', (string) $amount));
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;

        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $minor = ((int) $whole) * (10 ** $scale) + ($kept === '' ? 0 : (int) $kept);

        // Round half-up on the first dropped digit.
        if ((int) substr($fraction, $scale, 1) >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }

    /** Render minor units back as a fixed-point decimal string for storage/JSON. */
    public static function fromMinor(int $minor, int $scale = self::SCALE): string
    {
        $divisor = 10 ** $scale;
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        if ($scale === 0) {
            return $sign.$absolute;
        }

        return sprintf('%s%d.%s', $sign, intdiv($absolute, $divisor), str_pad((string) ($absolute % $divisor), $scale, '0', STR_PAD_LEFT));
    }

    /**
     * unit price x quantity, where the quantity itself may be fractional.
     * Both operands are integers, so the only imprecision possible is the one
     * deliberate half-up rounding back down to minor units.
     */
    public static function multiplyByQuantity(int $priceMinor, int|float|string|null $quantity): int
    {
        $quantityScaled = self::toMinor($quantity, self::QUANTITY_SCALE);

        return self::divideRoundHalfUp($priceMinor * $quantityScaled, 10 ** self::QUANTITY_SCALE);
    }

    /**
     * A percentage of an amount (tax, for example). The rate is scaled to
     * basis points first so 18.5% is exact rather than 18.499999%.
     */
    public static function percentageOf(int $amountMinor, int|float|string|null $rate): int
    {
        $rateScaled = self::toMinor($rate, self::RATE_SCALE);

        return self::divideRoundHalfUp($amountMinor * $rateScaled, 100 * (10 ** self::RATE_SCALE));
    }

    /** Integer division rounding halves away from zero, symmetric for negatives. */
    public static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Cannot divide by zero.');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $absoluteNumerator = abs($numerator);
        $absoluteDenominator = abs($denominator);

        $quotient = intdiv($absoluteNumerator, $absoluteDenominator);
        $remainder = $absoluteNumerator % $absoluteDenominator;

        if ($remainder * 2 >= $absoluteDenominator) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    /**
     * Stringify a float without scientific notation, keeping enough digits to
     * survive the round trip for realistic monetary values.
     */
    private static function floatToString(int|float $amount): string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }

        return rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.') ?: '0';
    }
}
