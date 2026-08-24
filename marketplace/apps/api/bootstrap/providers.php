<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    // Phase 11. Registers the provider manager, the AI tool inventory and the AI
    // burst rate limiter. Kept separate from AppServiceProvider so the AI layer's
    // wiring is reviewable on its own.
    AiServiceProvider::class,
    // One named rate limiter per abuse surface, driven by config/antispam.php. The
    // `ai` limiter deliberately stays in AiServiceProvider, beside the daily spend
    // quota it works with.
    RateLimitServiceProvider::class,
];
