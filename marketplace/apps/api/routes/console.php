<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 5 housekeeping: relabel quotes whose validity window has closed.
// Purely cosmetic — expiry itself is enforced live on every read and decision
// (see Quote::hasExpired), so nothing breaks if the scheduler is not running.
Schedule::command('quotes:expire')->hourly();
