<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend origin
    |--------------------------------------------------------------------------
    |
    | Where the SPA is served from. Distinct from APP_URL, which is this API.
    |
    | Needed because any link the backend emails to a user must land on a page the
    | user can actually use, and every such page belongs to the SPA. The password
    | reset link is the first case; email verification and notification deep-links
    | will be the next.
    |
    | Deliberately not derived from the incoming request. A queued email is
    | generated outside any request context, and trusting a request header to build
    | an emailed link is how host-header injection turns a password reset into a
    | phishing link pointing at an attacker's domain.
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'default_currency' => env('MARKETPLACE_DEFAULT_CURRENCY', 'INR'),

    'supported_currencies' => ['INR', 'USD', 'EUR'],

    /*
    |--------------------------------------------------------------------------
    | Business Matching Engine (Phase 4)
    |--------------------------------------------------------------------------
    |
    | The matching engine is deliberately deterministic and rule based — no
    | AI, no learned weights. Every dial lives here so the marketplace can
    | be re-tuned per deployment without touching the algorithm, and so the
    | algorithm itself never contains a hardcoded business name, city,
    | category or industry.
    |
    | `weights` are relative: a score is computed as the weighted average of
    | each dimension's 0..1 sub-score, expressed as a 0..100 percentage. The
    | defaults reproduce the Phase 4 spec (25/20/20/15/10/10) but they do not
    | have to sum to 100 — they are normalised by their own total.
    |
    */

    'matching' => [

        'weights' => [
            'category' => (float) env('MARKETPLACE_MATCH_WEIGHT_CATEGORY', 25),
            'capability' => (float) env('MARKETPLACE_MATCH_WEIGHT_CAPABILITY', 20),
            'location' => (float) env('MARKETPLACE_MATCH_WEIGHT_LOCATION', 20),
            'budget' => (float) env('MARKETPLACE_MATCH_WEIGHT_BUDGET', 15),
            'availability' => (float) env('MARKETPLACE_MATCH_WEIGHT_AVAILABILITY', 10),
            'trust' => (float) env('MARKETPLACE_MATCH_WEIGHT_TRUST', 10),
        ],

        // A candidate scoring below this percentage is never invited — a weak
        // match wastes the customer's attention and the business's time.
        'minimum_score' => (float) env('MARKETPLACE_MATCH_MINIMUM_SCORE', 35),

        // Hard cap on how many businesses a single requirement is matched to.
        'max_matches' => (int) env('MARKETPLACE_MATCH_MAX_MATCHES', 20),

        // Safety valve on the candidate pool pulled out of the database
        // before scoring, so a very broad category can never load the whole
        // businesses table into memory.
        'candidate_limit' => (int) env('MARKETPLACE_MATCH_CANDIDATE_LIMIT', 300),

        'category' => [
            // Score awarded when the business's own primary category is the
            // requirement's category, versus a parent/child of it.
            'exact_primary' => 1.0,
            'related_primary' => 0.6,
            // Same, but inferred from the business's catalogue (products,
            // services, listings) rather than its declared primary category.
            'exact_catalog' => 0.8,
            'related_catalog' => 0.5,
        ],

        'capability' => [
            'exact_category' => 0.7,
            'related_category' => 0.45,
            'uncategorised' => 0.15,
            // Quantity fit, added on top of the base and clamped to 1.0.
            'quantity_within_range' => 0.3,
            'quantity_above_max' => 0.1,
            'quantity_below_min' => 0.0,
            'quantity_unknown' => 0.15,
        ],

        'location' => [
            'same_city' => 1.0,
            'declared_service_area' => 1.0,
            'same_state' => 0.6,
            'same_country' => 0.2,
            // Coordinate fallback: a business inside this radius scores
            // between `nearby_floor` and 1.0 on a linear taper.
            'nearby_radius_km' => (float) env('MARKETPLACE_MATCH_NEARBY_RADIUS_KM', 150),
            'nearby_floor' => 0.35,
            // Used when the requirement carries no location at all — the
            // dimension can't be judged, so it must not be punished.
            'unknown' => 0.5,
        ],

        'budget' => [
            'overlap' => 1.0,
            // The business's indicative pricing sits below the customer's
            // minimum budget: still perfectly compatible, marginally penalised
            // because it may signal a different tier of supplier.
            'below_budget' => 0.9,
            // How far over the customer's ceiling an indicative price may sit
            // before the dimension scores zero, as a fraction of that ceiling.
            'over_budget_tolerance' => (float) env('MARKETPLACE_MATCH_OVER_BUDGET_TOLERANCE', 1.0),
            'unknown' => 0.5,
        ],

        'availability' => [
            'unavailable' => 0.0,
            'available' => 0.75,
            // Available and the requirement has no deadline to miss.
            'no_deadline' => 0.85,
            'meets_deadline' => 1.0,
            'misses_deadline' => 0.4,
        ],

        'trust' => [
            // Sub-weights inside the trust dimension itself.
            'weights' => [
                'verification' => 0.4,
                'rating' => 0.3,
                'tenure' => 0.2,
                'responsiveness' => 0.1,
            ],
            'max_rating' => 5.0,
            // Reviews needed before a rating is trusted at face value; below
            // this the rating is shrunk towards neutral.
            'rating_confidence_reviews' => 10,
            // Years in business at which the tenure signal saturates.
            'tenure_saturation_years' => (int) env('MARKETPLACE_TENURE_SATURATION_YEARS', 15),
            // Responsiveness is measured from the business's own history of
            // replying to earlier match invitations. Below this many prior
            // invitations there isn't enough history to judge, so the signal
            // stays neutral instead of penalising a new business.
            'responsiveness_min_invitations' => 3,
            'neutral' => 0.5,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Trust layer (Phase 9)
    |--------------------------------------------------------------------------
    |
    | The trust score is deliberately transparent and configurable: every factor is
    | listed here with its weight, and the API returns the per-factor breakdown
    | alongside the score so a business can see exactly what to improve.
    |
    | Nothing is invented. A factor with no data contributes nothing and is reported
    | as unavailable rather than defaulted to a flattering number — an unrated new
    | business shows "not enough data yet", never a made-up 4.5.
    |
    */

    'trust' => [

        'weights' => [
            'verification' => (float) env('MARKETPLACE_TRUST_WEIGHT_VERIFICATION', 25),
            'profile_completeness' => (float) env('MARKETPLACE_TRUST_WEIGHT_PROFILE', 15),
            'completed_orders' => (float) env('MARKETPLACE_TRUST_WEIGHT_ORDERS', 20),
            'review_quality' => (float) env('MARKETPLACE_TRUST_WEIGHT_REVIEWS', 20),
            'responsiveness' => (float) env('MARKETPLACE_TRUST_WEIGHT_RESPONSIVENESS', 10),
            'reliability' => (float) env('MARKETPLACE_TRUST_WEIGHT_RELIABILITY', 5),
            'account_age' => (float) env('MARKETPLACE_TRUST_WEIGHT_ACCOUNT_AGE', 5),
        ],

        // Volume at which a factor is considered fully evidenced.
        'orders_saturation' => (int) env('MARKETPLACE_TRUST_ORDERS_SATURATION', 20),
        'reviews_confidence' => (int) env('MARKETPLACE_TRUST_REVIEWS_CONFIDENCE', 5),
        'account_age_saturation_days' => (int) env('MARKETPLACE_TRUST_ACCOUNT_AGE_DAYS', 730),
        // Minimum invitations before responsiveness is judged at all.
        'responsiveness_min_invitations' => (int) env('MARKETPLACE_TRUST_RESPONSIVENESS_MIN', 3),
        // Minimum orders before cancellation/dispute behaviour is judged.
        'reliability_min_orders' => (int) env('MARKETPLACE_TRUST_RELIABILITY_MIN_ORDERS', 3),

        // Fields that make up a "complete" profile. Category-agnostic on purpose.
        'profile_fields' => [
            'name', 'about', 'phone', 'email', 'logo_path',
            'category_id', 'location_id', 'business_type', 'years_in_business', 'trade_type',
        ],

        'max_rating' => 5.0,
    ],

];
