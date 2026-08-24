<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Produces the human-readable quote reference, e.g. QT-2026-000001.
 *
 * The year is read from the clock, never hardcoded, and the sequence restarts
 * each year. The number is derived from the highest existing sequence for that
 * year rather than from the table's auto-increment id, so the customer-facing
 * reference stays tidy and is not a database internal.
 *
 * Uniqueness has two layers: the read is taken inside the caller's transaction
 * with a row lock so concurrent callers queue rather than race, and the
 * `quote_number` column is unique so a collision surfaces as an error instead
 * of a duplicate document. `nextFor()` also retries on a unique violation,
 * which is what makes two simultaneous first-quotes-of-the-year safe.
 */
class QuoteNumberGenerator
{
    private const PREFIX = 'QT';

    private const SEQUENCE_LENGTH = 6;

    private const MAX_ATTEMPTS = 5;

    /**
     * Runs the callback with a freshly allocated quote number, retrying if the
     * number was taken in the meantime.
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback
     * @return TReturn
     */
    public function withNumber(callable $callback): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $callback($this->next());
            } catch (QueryException $exception) {
                if (! $this->isDuplicateQuoteNumber($exception)) {
                    throw $exception;
                }

                $lastException = $exception;
            }
        }

        throw new RuntimeException('Could not allocate a unique quote number.', 0, $lastException);
    }

    /** The next reference for the current year. */
    public function next(?string $year = null): string
    {
        $year ??= now()->format('Y');
        $prefix = sprintf('%s-%s-', self::PREFIX, $year);

        // lockForUpdate serialises concurrent allocations when the caller is
        // inside a transaction; on SQLite (single writer) it is a no-op, and the
        // unique index plus retry still guarantee correctness.
        $latest = Quote::query()
            ->where('quote_number', 'like', $prefix.'%')
            ->when(DB::connection()->getDriverName() !== 'sqlite', fn ($q) => $q->lockForUpdate())
            ->orderByDesc('quote_number')
            ->value('quote_number');

        $sequence = $latest !== null
            ? ((int) substr($latest, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $sequence, self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function isDuplicateQuoteNumber(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'quote_number')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
