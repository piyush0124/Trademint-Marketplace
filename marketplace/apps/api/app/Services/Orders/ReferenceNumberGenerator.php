<?php

namespace App\Services\Orders;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Human-readable, per-year reference numbers: ORD-2026-000001, PAY-2026-000001.
 *
 * Generalised from Phase 5's quote numbering so orders and payments share one
 * implementation rather than three near-identical copies. The year comes from the
 * clock, never a literal, and the sequence restarts annually.
 *
 * Uniqueness has the same two layers as before: the read happens inside the
 * caller's transaction (with a row lock on engines that support it), and the
 * column's unique index is the final guard, with a retry on collision. That is
 * what makes two simultaneous first-of-the-year references safe.
 */
class ReferenceNumberGenerator
{
    private const SEQUENCE_LENGTH = 6;

    private const MAX_ATTEMPTS = 5;

    /**
     * Run the callback with a freshly allocated reference, retrying if the number
     * was taken in the meantime.
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback
     * @return TReturn
     */
    public function withNumber(string $prefix, string $table, string $column, callable $callback): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $callback($this->next($prefix, $table, $column));
            } catch (QueryException $exception) {
                if (! $this->isDuplicate($exception, $column)) {
                    throw $exception;
                }

                $lastException = $exception;
            }
        }

        throw new RuntimeException(sprintf('Could not allocate a unique %s reference.', $prefix), 0, $lastException);
    }

    public function next(string $prefix, string $table, string $column, ?string $year = null): string
    {
        $year ??= now()->format('Y');
        $fullPrefix = sprintf('%s-%s-', $prefix, $year);

        $query = DB::table($table)->where($column, 'like', $fullPrefix.'%');

        // lockForUpdate serialises concurrent allocation on MySQL/Postgres; SQLite
        // is a single writer, where the unique index plus retry still guarantee
        // correctness.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $latest = $query->orderByDesc($column)->value($column);

        $sequence = $latest !== null
            ? ((int) substr($latest, strlen($fullPrefix))) + 1
            : 1;

        return $fullPrefix.str_pad((string) $sequence, self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function isDuplicate(QueryException $exception, string $column): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, strtolower($column))
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
