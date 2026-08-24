<?php

namespace App\Services\Ai\Extraction;

use Carbon\CarbonImmutable;

/**
 * Turns "in 3 months" or "by 15 March" into a date.
 *
 * Deterministic, like the amount parser, and for the same reason: a required-by date
 * drives matching and supplier deadlines, and a model asked to do date arithmetic
 * will occasionally produce last year. Here the maths is explicit and the reference
 * "today" is injectable, so the tests do not drift as the calendar moves.
 *
 * Returns null when nothing date-like is present. The requirement stays incomplete
 * and the assistant asks — which is the correct outcome, because inventing a deadline
 * would send suppliers a commitment the buyer never made.
 */
class TimelineNormaliser
{
    /** @var array<string, string> Relative units, including Hindi transliterations. */
    private const UNITS = [
        'days' => 'days', 'day' => 'days', 'din' => 'days', 'dino' => 'days',
        'weeks' => 'weeks', 'week' => 'weeks', 'hafte' => 'weeks', 'hafta' => 'weeks',
        'months' => 'months', 'month' => 'months', 'mahine' => 'months', 'mahina' => 'months', 'maheene' => 'months',
        'years' => 'years', 'year' => 'years', 'saal' => 'years', 'varsh' => 'years',
        'quarters' => 'quarters', 'quarter' => 'quarters',
    ];

    /**
     * Urgency words with no calendar meaning, mapped to a day count.
     *
     * These are a marketplace convention rather than a fact — "asap" meaning a week is
     * a judgement — so they live here where they can be reviewed and changed, not buried
     * in a regex. Kept separate from the calendar phrases below because those *do* have
     * an exact answer.
     *
     * @var array<string, int>
     */
    private const FUZZY_DAYS = [
        'immediately' => 3,
        'immediate' => 3,
        'turant' => 3,
        'asap' => 7,
        'urgent' => 7,
        'urgently' => 7,
        'jaldi' => 7,
    ];

    /**
     * Phrases that name a real calendar boundary.
     *
     * Resolved against the actual calendar rather than a fixed day count. "Next month"
     * said on the 1st and on the 28th mean different numbers of days but the same month,
     * and a fixed 30 or 60 gets one of those two cases wrong every time.
     *
     * @var array<int, string>
     */
    private const CALENDAR_PHRASES = [
        'end of this week', 'this week', 'next week',
        'end of this month', 'end of month', 'this month', 'next month',
        'end of this quarter', 'this quarter', 'next quarter',
        'end of this year', 'end of year', 'this year', 'next year',
    ];

    /**
     * @return array{date: string|null, text: string|null, urgency: string|null}
     */
    public function normalise(?string $text, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();

        if ($text === null || trim($text) === '') {
            return ['date' => null, 'text' => null, 'urgency' => null];
        }

        $original = trim($text);
        $clean = mb_strtolower($original);

        $date = $this->explicitDate($clean, $today)
            ?? $this->relativeDate($clean, $today)
            ?? $this->fuzzyDate($clean, $today);

        return [
            'date' => $date?->toDateString(),
            // The phrase as written is kept alongside the date. "3 months" and a
            // computed date are different information, and a supplier reading the
            // requirement benefits from the buyer's own words.
            'text' => $original,
            'urgency' => $this->urgencyFor($clean, $date, $today),
        ];
    }

    /**
     * Map a timeline onto the marketplace's existing urgency scale.
     *
     * Derived from the interval rather than from adjectives where possible: a date
     * eight days out is "high" whether or not the buyer wrote "urgent".
     */
    public function urgencyFor(string $clean, ?CarbonImmutable $date, CarbonImmutable $today): ?string
    {
        foreach (['urgent', 'urgently', 'asap', 'immediately', 'immediate', 'turant', 'jaldi'] as $marker) {
            if (str_contains($clean, $marker)) {
                return 'urgent';
            }
        }

        if ($date === null) {
            return null;
        }

        $days = $today->diffInDays($date, false);

        return match (true) {
            $days <= 7 => 'urgent',
            $days <= 30 => 'high',
            $days <= 120 => 'normal',
            default => 'low',
        };
    }

    /** An actual date: "15 March", "15/03/2027", "2027-03-15", "March 2027". */
    private function explicitDate(string $text, CarbonImmutable $today): ?CarbonImmutable
    {
        // ISO first — unambiguous, and what the model is asked to produce.
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $text, $m) === 1) {
            return $this->safeDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Day-first numeric, which is the Indian convention: 15/03/2027, 15-3-27.
        if (preg_match('/\b(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})\b/', $text, $m) === 1) {
            $year = (int) $m[3];
            $year = $year < 100 ? 2000 + $year : $year;

            return $this->safeDate($year, (int) $m[2], (int) $m[1]);
        }

        $months = 'january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec';

        // "15 March 2027" / "15 March"
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+('.$months.')\.?\s*(\d{4})?\b/u', $text, $m) === 1) {
            $month = $this->monthNumber($m[2]);
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $today->year;
            $date = $this->safeDate($year, $month, (int) $m[1]);

            // No year given and the date has passed: they mean next year.
            return $date !== null && $date->lessThan($today) && (! isset($m[3]) || $m[3] === '')
                ? $date->addYear()
                : $date;
        }

        // "March 2027" / "by March" — end of that month, since no day was named.
        if (preg_match('/\b('.$months.')\.?\s*(\d{4})?\b/u', $text, $m) === 1) {
            $month = $this->monthNumber($m[1]);
            $year = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $today->year;
            $date = $this->safeDate($year, $month, 1)?->endOfMonth()->startOfDay();

            return $date !== null && $date->lessThan($today) && (! isset($m[2]) || $m[2] === '')
                ? $date->addYear()
                : $date;
        }

        return null;
    }

    /** "in 3 months", "within 2 weeks", "3 mahine mein", "45 days". */
    private function relativeDate(string $text, CarbonImmutable $today): ?CarbonImmutable
    {
        $units = implode('|', array_map('preg_quote', array_keys(self::UNITS)));

        if (preg_match('/(\d+)\s*('.$units.')\b/u', $text, $m) !== 1) {
            return null;
        }

        $amount = (int) $m[1];
        $unit = self::UNITS[$m[2]] ?? null;

        // A guard against a runaway number producing a date centuries away.
        if ($unit === null || $amount <= 0 || $amount > 500) {
            return null;
        }

        return match ($unit) {
            'days' => $today->addDays($amount),
            'weeks' => $today->addWeeks($amount),
            'months' => $today->addMonths($amount),
            'quarters' => $today->addMonths($amount * 3),
            'years' => $today->addYears($amount),
            default => null,
        };
    }

    /**
     * "urgent", "asap", "next month" — no number, but still a deadline.
     *
     * Calendar phrases are tried first and longest-first, so "next month" is not
     * swallowed by "month" and "end of this year" is not matched as "this year".
     */
    private function fuzzyDate(string $text, CarbonImmutable $today): ?CarbonImmutable
    {
        $calendar = self::CALENDAR_PHRASES;
        usort($calendar, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($calendar as $phrase) {
            if (str_contains($text, $phrase)) {
                return $this->calendarBoundary($phrase, $today);
            }
        }

        $fuzzy = self::FUZZY_DAYS;
        uksort($fuzzy, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($fuzzy as $phrase => $days) {
            if (str_contains($text, $phrase)) {
                return $today->addDays($days);
            }
        }

        return null;
    }

    /**
     * Resolve a calendar phrase to a date.
     *
     * "This X" resolves to the end of the current period, because a buyer saying "this
     * month" is giving themselves until the month runs out. "Next X" resolves to the
     * *end* of that period for the same reason — committing a supplier to the 1st when
     * the buyer said "next month" would invent a deadline three weeks tighter than they
     * asked for.
     */
    private function calendarBoundary(string $phrase, CarbonImmutable $today): CarbonImmutable
    {
        return match (true) {
            str_contains($phrase, 'next week') => $today->addWeek()->endOfWeek()->startOfDay(),
            str_contains($phrase, 'week') => $today->endOfWeek()->startOfDay(),
            str_contains($phrase, 'next month') => $today->addMonthNoOverflow()->endOfMonth()->startOfDay(),
            str_contains($phrase, 'month') => $today->endOfMonth()->startOfDay(),
            str_contains($phrase, 'next quarter') => $today->addMonthsNoOverflow(3)->endOfQuarter()->startOfDay(),
            str_contains($phrase, 'quarter') => $today->endOfQuarter()->startOfDay(),
            str_contains($phrase, 'next year') => $today->addYear()->endOfYear()->startOfDay(),
            default => $today->endOfYear()->startOfDay(),
        };
    }

    private function monthNumber(string $name): int
    {
        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];

        return $months[mb_substr($name, 0, 3)] ?? 1;
    }

    /** Reject impossible dates rather than letting Carbon roll them over silently. */
    private function safeDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $year < 2000 || $year > 2100) {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)?->startOfDay();
    }
}
