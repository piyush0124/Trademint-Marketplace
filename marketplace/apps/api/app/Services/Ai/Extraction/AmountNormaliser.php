<?php

namespace App\Services\Ai\Extraction;

/**
 * Turns however an Indian buyer wrote a number into an integer.
 *
 * Deliberately **not** an AI job. Money is the one field where a plausible-looking
 * mistake is expensive: a model that reads "15 lakh" as 15 understates a budget by
 * five orders of magnitude, and it will do so confidently. This is ordinary
 * deterministic parsing — same input, same output, every time, no provider call, and
 * unit-testable to the rupee.
 *
 * The AI is still asked to normalise amounts in its extraction (fewer clarifying
 * rounds when it gets them right), but whatever it returns is passed through here
 * before anything is stored. The model is a convenience; this is the authority.
 *
 * Handles: 500000 · 5,00,000 · 500,000 · ₹5 lakh · 5L · 5 lakhs · 5 lac · five lakh ·
 * 1.5 crore · 1.5cr · 15k · 15 thousand · "50 hazaar" · "Rs. 2.5 Lakh".
 */
class AmountNormaliser
{
    /**
     * Indian scale words and their multipliers.
     *
     * Spellings are what people actually type, including the Hindi transliterations
     * ("lakh"/"lac"/"laakh", "hazaar"/"hazar"). Ordered longest-first when matched so
     * "crore" is not partially consumed by a shorter alternative.
     *
     * @var array<string, int>
     */
    private const SCALES = [
        'arab' => 1_000_000_000,
        'kharab' => 100_000_000_000,
        'crores' => 10_000_000,
        'crore' => 10_000_000,
        'karor' => 10_000_000,
        'cr' => 10_000_000,
        'lakhs' => 100_000,
        'lakh' => 100_000,
        'laakh' => 100_000,
        'lacs' => 100_000,
        'lac' => 100_000,
        'lak' => 100_000,
        'million' => 1_000_000,
        'millions' => 1_000_000,
        'mn' => 1_000_000,
        'billion' => 1_000_000_000,
        'thousands' => 1_000,
        'thousand' => 1_000,
        'hazaar' => 1_000,
        'hazar' => 1_000,
        'hazzar' => 1_000,
        'k' => 1_000,
        'l' => 100_000,
    ];

    /**
     * Spelled-out numbers, for "five lakh" and "two point five crore".
     *
     * Stops at twenty plus the tens, which covers essentially every real budget
     * phrase. Beyond that people write digits.
     *
     * @var array<string, float>
     */
    private const WORDS = [
        'half' => 0.5,
        'one' => 1, 'ek' => 1,
        'two' => 2, 'do' => 2,
        'three' => 3, 'teen' => 3,
        'four' => 4, 'char' => 4,
        'five' => 5, 'paanch' => 5, 'panch' => 5,
        'six' => 6, 'chah' => 6, 'chhah' => 6,
        'seven' => 7, 'saat' => 7,
        'eight' => 8, 'aath' => 8,
        'nine' => 9, 'nau' => 9,
        'ten' => 10, 'das' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
        'fifteen' => 15, 'pandrah' => 15,
        'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
        'twenty' => 20, 'bees' => 20,
        'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'pachaas' => 50,
        'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
        'hundred' => 100, 'sau' => 100,
    ];

    /**
     * Parse a single amount.
     *
     * Returns null rather than guessing when the input has no number in it at all —
     * a null budget is honest, a zero budget is a lie that would break matching.
     */
    public function normalise(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        // Already a number. Guard against a model returning a float in scientific
        // notation, and against negatives, which are never a valid budget.
        if (is_int($value) || is_float($value)) {
            return $value >= 0 && is_finite((float) $value) ? (int) round((float) $value) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = $this->clean($value);

        if ($text === '') {
            return null;
        }

        // Digits with a scale word: "2.5 lakh", "15k", "1.5cr".
        $scaled = $this->parseScaled($text);

        if ($scaled !== null) {
            return $scaled;
        }

        // Words with a scale word: "five lakh", "do crore".
        $worded = $this->parseWorded($text);

        if ($worded !== null) {
            return $worded;
        }

        // Plain digits, with or without separators.
        return $this->parsePlain($text);
    }

    /**
     * Parse a budget phrase that may describe a range.
     *
     * "between 5 and 10 lakh" and "5-10 lakh" both mean 500000–1000000, which needs
     * the scale word to carry backwards to the first figure — the single most common
     * way a naive parser gets an Indian budget range wrong.
     *
     * @return array{min: int|null, max: int|null}
     */
    public function normaliseRange(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return ['min' => null, 'max' => null];
        }

        $clean = $this->clean($text);

        // Split on a range separator: hyphen, "to", "and", or an en dash.
        $parts = preg_split('/\s*(?:-|–|—|\bto\b|\bse\b|\baur\b|\band\b)\s*/u', $clean, 2);

        if (! is_array($parts) || count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            $single = $this->normalise($clean);

            // A single figure is read as a ceiling. "Budget 15 lakh" means "up to",
            // not "at least" — treating it as a floor would exclude every supplier
            // who could do it cheaper.
            return ['min' => null, 'max' => $single];
        }

        [$left, $right] = $parts;

        $max = $this->normalise($right);

        // If the left side has no scale word of its own, inherit the right's:
        // "5-10 lakh" → 5 lakh and 10 lakh, not 5 and 1000000.
        $min = $this->hasScaleWord($left)
            ? $this->normalise($left)
            : $this->normalise($left.' '.$this->scaleWordIn($right));

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Strip currency noise and lowercase.
     *
     * The rupee sign, "rs", "inr" and "/-" all carry no numeric meaning, and leaving
     * them in defeats the digit patterns below.
     */
    private function clean(string $value): string
    {
        $text = mb_strtolower(trim($value));

        $text = str_replace(['₹', 'रु', '/-', '/='], ' ', $text);
        $text = (string) preg_replace('/\b(?:rs|inr|rupees|rupee|rupaye|rupay)\b\.?/u', ' ', $text);
        // "approx", "around", "about" and friends are hints about precision, not value.
        $text = (string) preg_replace('/\b(?:approx|approximately|around|about|nearly|upto|up\s+to|max|maximum|min|minimum|budget|of)\b/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /** Digits followed by a scale word, e.g. "2.5 lakh", "15k", "1.5cr". */
    private function parseScaled(string $text): ?int
    {
        $alternatives = $this->scaleAlternatives();

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*('.$alternatives.')\b/u', $text, $matches) !== 1) {
            return null;
        }

        // A decimal comma ("2,5 lakh") is treated as a decimal point; an Indian
        // thousands comma would not appear directly before a scale word.
        $number = (float) str_replace(',', '.', $matches[1]);
        $multiplier = self::SCALES[$matches[2]] ?? 1;

        return (int) round($number * $multiplier);
    }

    /** Spelled-out number followed by a scale word, e.g. "five lakh". */
    private function parseWorded(string $text): ?int
    {
        $alternatives = $this->scaleAlternatives();
        $wordAlternatives = implode('|', array_map('preg_quote', array_keys(self::WORDS)));

        // Allows "two and a half lakh" via the optional trailing word.
        if (preg_match('/\b('.$wordAlternatives.')\b(?:\s+(?:point|and a)\s+\b('.$wordAlternatives.')\b)?\s*('.$alternatives.')\b/u', $text, $matches) !== 1) {
            return null;
        }

        $base = self::WORDS[$matches[1]] ?? null;

        if ($base === null) {
            return null;
        }

        // "two point five" → 2.5; "two and a half" → 2.5.
        if (! empty($matches[2])) {
            $fraction = self::WORDS[$matches[2]] ?? null;

            if ($fraction !== null) {
                $base += $fraction < 1 ? $fraction : $fraction / 10;
            }
        }

        return (int) round($base * (self::SCALES[$matches[3]] ?? 1));
    }

    /**
     * Plain digits, tolerating Indian (5,00,000) and Western (500,000) grouping.
     *
     * Separators are simply removed. Distinguishing a thousands separator from a
     * decimal point is genuinely ambiguous in mixed input, and for a budget the
     * larger reading is the safe one — a rupee of decimal precision never matters
     * here, whereas dropping three zeros does.
     */
    private function parsePlain(string $text): ?int
    {
        if (preg_match('/\d[\d,\s]*(?:\.\d+)?/u', $text, $matches) !== 1) {
            return null;
        }

        $number = str_replace([',', ' '], '', $matches[0]);

        if (! is_numeric($number)) {
            return null;
        }

        $amount = (float) $number;

        return $amount >= 0 ? (int) round($amount) : null;
    }

    private function hasScaleWord(string $text): bool
    {
        return preg_match('/\b('.$this->scaleAlternatives().')\b/u', $text) === 1;
    }

    private function scaleWordIn(string $text): string
    {
        return preg_match('/\b('.$this->scaleAlternatives().')\b/u', $text, $matches) === 1 ? $matches[1] : '';
    }

    /** Longest-first so "crore" wins over "cr" and "lakhs" over "lakh". */
    private function scaleAlternatives(): string
    {
        $keys = array_keys(self::SCALES);

        usort($keys, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map('preg_quote', $keys));
    }
}
