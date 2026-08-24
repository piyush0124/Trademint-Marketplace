<?php

namespace App\Providers;

use App\Events\QuoteAccepted;
use App\Listeners\CreateOrderFromAcceptedQuote;
use App\Listeners\SendMarketplaceNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Phase 7: an accepted quote becomes an order.
         *
         * Registered explicitly rather than relying on auto-discovery so the
         * hand-off between phases is visible in one place.
         */
        Event::listen(QuoteAccepted::class, CreateOrderFromAcceptedQuote::class);

        /*
         * The notification layer these events were always waiting for.
         *
         * Until this was added, eighteen of the nineteen domain events had no
         * listener at all — they fired and nothing happened. A buyer posted a
         * requirement, three suppliers quoted, and nothing told them.
         *
         * Registered as a subscriber rather than nineteen separate Event::listen
         * calls so that "who is notified when X happens" is one readable file
         * (SendMarketplaceNotifications) instead of a policy scattered across the
         * provider. The mapping itself lives in that class's subscribe() method.
         */
        Event::subscribe(SendMarketplaceNotifications::class);
    }
}
