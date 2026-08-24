<?php

namespace App\Console\Commands;

use App\Services\QuoteService;
use Illuminate\Console\Command;

/**
 * Relabels quotes whose validity window has closed.
 *
 * This is housekeeping, not enforcement. Expiry is always evaluated live from
 * expires_at (Quote::hasExpired), so an expired quote cannot be accepted even if
 * this command has never run. Its only job is to keep dashboards and status
 * filters tidy, and to fire QuoteExpired so a future notification layer can tell
 * both parties.
 *
 * Safe to run repeatedly; a quote already marked expired is skipped.
 */
class ExpireQuotes extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Mark sent/viewed quotes whose validity date has passed as expired';

    public function handle(QuoteService $quotes): int
    {
        $expired = $quotes->expireLapsedQuotes();

        $this->info($expired === 0
            ? 'No quotes needed expiring.'
            : sprintf('Expired %d quote(s).', $expired));

        return self::SUCCESS;
    }
}
