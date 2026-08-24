<?php

namespace Tests\Unit;

use App\Services\Ai\Extraction\TimelineNormaliser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Deadline parsing.
 *
 * "Today" is injected so these assertions do not rot as the calendar moves — a date
 * test that passes only in 2026 is worse than no test.
 */
class TimelineNormaliserTest extends TestCase
{
    private TimelineNormaliser $normaliser;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normaliser = new TimelineNormaliser;
        $this->today = CarbonImmutable::create(2026, 3, 10)->startOfDay();
    }

    public function test_it_reads_relative_durations(): void
    {
        $this->assertSame('2026-06-10', $this->normaliser->normalise('3 months', $this->today)['date']);
        $this->assertSame('2026-03-24', $this->normaliser->normalise('within 2 weeks', $this->today)['date']);
        $this->assertSame('2026-04-24', $this->normaliser->normalise('45 days', $this->today)['date']);
        $this->assertSame('2026-06-10', $this->normaliser->normalise('in 1 quarter', $this->today)['date']);
    }

    public function test_it_reads_hindi_transliterated_durations(): void
    {
        $this->assertSame('2026-06-10', $this->normaliser->normalise('3 mahine mein', $this->today)['date']);
        $this->assertSame('2026-03-24', $this->normaliser->normalise('2 hafte', $this->today)['date']);
        $this->assertSame('2026-03-20', $this->normaliser->normalise('10 din', $this->today)['date']);
    }

    public function test_it_reads_explicit_dates_in_indian_day_first_order(): void
    {
        $this->assertSame('2026-04-15', $this->normaliser->normalise('by 15/04/2026', $this->today)['date']);
        $this->assertSame('2026-04-15', $this->normaliser->normalise('15 April 2026', $this->today)['date']);
        $this->assertSame('2027-01-20', $this->normaliser->normalise('2027-01-20', $this->today)['date']);
    }

    public function test_a_date_already_past_this_year_rolls_to_next_year(): void
    {
        // "by 5 January" said in March means next January, not two months ago.
        $this->assertSame('2027-01-05', $this->normaliser->normalise('by 5 January', $this->today)['date']);
    }

    public function test_a_bare_month_resolves_to_its_end(): void
    {
        // No day was named, so the buyer means "by the end of".
        $this->assertSame('2026-05-31', $this->normaliser->normalise('by May', $this->today)['date']);
    }

    public function test_it_reads_urgency_words_with_no_calendar_meaning(): void
    {
        $this->assertSame('2026-03-17', $this->normaliser->normalise('asap', $this->today)['date']);
        $this->assertSame('2026-03-13', $this->normaliser->normalise('immediately', $this->today)['date']);
        $this->assertSame('2026-03-13', $this->normaliser->normalise('turant chahiye', $this->today)['date']);
    }

    /**
     * Calendar phrases resolve against the real calendar, not a fixed day count.
     *
     * "Next month" said on the 1st and on the 28th mean the same month but a very
     * different number of days, which is why a fixed offset gets one of those wrong.
     */
    public function test_calendar_phrases_resolve_to_real_period_boundaries(): void
    {
        // From 10 March 2026.
        $this->assertSame('2026-03-31', $this->normaliser->normalise('this month', $this->today)['date']);
        $this->assertSame('2026-04-30', $this->normaliser->normalise('next month', $this->today)['date']);
        $this->assertSame('2026-03-31', $this->normaliser->normalise('end of this quarter', $this->today)['date']);
        $this->assertSame('2026-12-31', $this->normaliser->normalise('end of year', $this->today)['date']);
    }

    public function test_the_same_phrase_tracks_the_date_it_was_said_on(): void
    {
        $lateInMonth = CarbonImmutable::create(2026, 3, 28)->startOfDay();

        // Same words, same month boundary — a fixed 30-day offset would have produced
        // 27 April here and 9 April from the 10th.
        $this->assertSame('2026-04-30', $this->normaliser->normalise('next month', $lateInMonth)['date']);
    }

    public function test_urgency_is_derived_from_the_interval(): void
    {
        $this->assertSame('urgent', $this->normaliser->normalise('in 5 days', $this->today)['urgency']);
        $this->assertSame('high', $this->normaliser->normalise('in 3 weeks', $this->today)['urgency']);
        $this->assertSame('normal', $this->normaliser->normalise('3 months', $this->today)['urgency']);
        $this->assertSame('low', $this->normaliser->normalise('next year', $this->today)['urgency']);
    }

    public function test_an_explicit_urgency_word_wins_over_a_distant_date(): void
    {
        // "urgent, in 6 months" is contradictory; the adjective is the stronger signal
        // about how the buyer wants to be treated.
        $this->assertSame('urgent', $this->normaliser->normalise('urgent - 6 months', $this->today)['urgency']);
    }

    public function test_it_keeps_the_buyers_own_wording_alongside_the_date(): void
    {
        $result = $this->normaliser->normalise('within 3 months', $this->today);

        $this->assertSame('2026-06-10', $result['date']);
        // A supplier reading the requirement benefits from the phrase, not just a date.
        $this->assertSame('within 3 months', $result['text']);
    }

    public function test_no_deadline_yields_null_rather_than_an_invented_one(): void
    {
        $this->assertNull($this->normaliser->normalise('no rush', $this->today)['date']);
        $this->assertNull($this->normaliser->normalise(null, $this->today)['date']);
        $this->assertNull($this->normaliser->normalise('', $this->today)['date']);
    }

    public function test_impossible_and_runaway_values_are_rejected(): void
    {
        $this->assertNull($this->normaliser->normalise('30 February 2026', $this->today)['date']);
        // 9000 months would produce a date in the year 2776.
        $this->assertNull($this->normaliser->normalise('9000 months', $this->today)['date']);
    }
}
