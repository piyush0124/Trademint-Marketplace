<?php

use App\Http\Controllers\Api\V1\AdminListingController;
use App\Http\Controllers\Api\V1\AdminModerationController;
use App\Http\Controllers\Api\V1\AiActionController;
use App\Http\Controllers\Api\V1\AiConversationController;
use App\Http\Controllers\Api\V1\AiFeedbackController;
use App\Http\Controllers\Api\V1\AiIntelligenceController;
use App\Http\Controllers\Api\V1\AiRequirementBuilderController;
use App\Http\Controllers\Api\V1\AiSearchController;
use App\Http\Controllers\Api\V1\AiUsageController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessPassportController;
use App\Http\Controllers\Api\V1\BusinessVerificationController;
use App\Http\Controllers\Api\V1\BusinessVerificationRequestController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\FormTokenController;
use App\Http\Controllers\Api\V1\IndustryController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\ListingController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\NegotiationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuoteComparisonController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\RequirementController;
use App\Http\Controllers\Api\V1\RequirementMatchController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\UserListingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    /*
     * Authentication.
     *
     * Registration and login carry the tightest limiters in the application:
     * these are the credential-stuffing and fake-account surfaces, and unlike
     * every other write here they are reachable without an account, so IP is the
     * only key available before the fact.
     *
     * `signup` and `auth` are separate limiters on purpose — burning the login
     * allowance with bad passwords must not also block a legitimate registration
     * from the same office.
     */
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:signup');
        Route::post('register/owner', [AuthController::class, 'registerOwner'])->middleware('throttle:signup');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

        /*
         * Password reset. Carries `throttle:password-reset` — the tightest limiter in
         * the config (3 per 15 minutes, keyed on IP) because each accepted request can
         * send an email, so abuse here spends our mail reputation on someone else's
         * spam complaint.
         *
         * Both endpoints are necessarily unauthenticated: the caller cannot sign in,
         * which is why they are here. `forgot-password` answers identically for known
         * and unknown addresses — see PasswordResetController for why that matters.
         */
        Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword'])
            ->middleware('throttle:password-reset');
        Route::post('reset-password', [PasswordResetController::class, 'resetPassword'])
            ->middleware('throttle:password-reset');
        // Lets the SPA fail fast on a dead link instead of asking for a new password
        // first and rejecting it after submission.
        Route::post('reset-password/verify', [PasswordResetController::class, 'verifyToken'])
            ->middleware('throttle:password-reset');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    /*
     * Notification inbox.
     *
     * `unread-count` is declared before the `{notification}` wildcard routes and is
     * throttled with `search` rather than a write limiter: the bell polls it, so it
     * is read-heavy by design and a tight limit here would break the badge for a
     * user with two tabs open.
     */
    Route::middleware('auth:sanctum')->prefix('notifications')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->middleware('throttle:search');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->middleware('throttle:search');
        Route::post('read-all', [NotificationController::class, 'markAllRead']);
        Route::post('{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('{notification}', [NotificationController::class, 'destroy']);
    });

    /*
     * Signed form token for the minimum-submission-time check. Unauthenticated,
     * because the guest lead form on a public listing is where it matters most.
     */
    Route::get('form-token', [FormTokenController::class, 'show'])->middleware('throttle:search');

    // Public catalog
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('locations/popular', [LocationController::class, 'popular']);
    Route::get('categories/{slug}/attribute-definitions', [CategoryController::class, 'attributeDefinitions']);
    Route::get('industries', [IndustryController::class, 'index']);

    /*
     * Public discovery. Throttled with `search`, which is by far the loosest
     * limiter in the config — these fire as a user types and paginates, and a
     * limit tight enough to matter here would break browsing for real users
     * while barely inconveniencing a scraper.
     */
    Route::middleware('throttle:search')->group(function (): void {
        // Public listings (published only)
        Route::get('listings', [ListingController::class, 'index']);
        Route::get('listings/{listing}', [ListingController::class, 'show']);

        // Public business profile + catalog
        Route::get('businesses', [BusinessController::class, 'index']);
    });

    /*
     * Literal `businesses/...` segments must be declared before the `{slug}`
     * wildcard below, because Laravel matches in registration order: left later,
     * `GET businesses/verification` would be swallowed by `businesses/{slug}` and
     * 404 as "no business with the slug 'verification'". The route is
     * authenticated in its own right; it lives here only for precedence.
     */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('businesses/verification', [BusinessVerificationRequestController::class, 'show']);
    });

    // Passport and reviews are public: a buyer must be able to judge a supplier
    // before signing up. Documents are never exposed here, only their statuses.
    Route::get('businesses/{slug}/passport', [BusinessPassportController::class, 'show']);
    Route::get('businesses/{slug}/reviews', [BusinessPassportController::class, 'reviews']);
    Route::get('businesses/{slug}', [BusinessController::class, 'show']);
    Route::get('businesses/{slug}/products', [ProductController::class, 'indexForBusiness']);
    Route::get('businesses/{slug}/services', [ServiceController::class, 'indexForBusiness']);

    /*
     * Public enquiry submission — works for guests and logged-in users alike.
     *
     * The only unauthenticated write in the application, and therefore the most
     * exposed: no account to rate-limit against and no history to judge. It gets
     * the tight `contact` limiter plus content-duplicate detection, which is what
     * catches one script posting the same pitch to every listing on the site.
     */
    Route::post('listings/{listing}/leads', [LeadController::class, 'store'])
        ->middleware(['throttle:contact', 'no-duplicates']);

    // Payment methods on offer (labels only, no secrets) and the provider webhook.
    // The webhook is necessarily unauthenticated: a provider cannot hold a session,
    // so its signature is the authentication (verified in PaymentService).
    Route::get('payments/methods', [PaymentController::class, 'methods']);
    Route::post('webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])
        ->middleware('throttle:60,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        // Owner listing workflow. Ownership itself is enforced by ListingPolicy —
        // these limiters are about volume, not authorisation.
        Route::post('listings', [ListingController::class, 'store'])
            ->middleware(['throttle:listings', 'no-duplicates']);
        Route::patch('listings/{listing}', [ListingController::class, 'update'])
            ->middleware('throttle:listings');
        Route::delete('listings/{listing}', [ListingController::class, 'destroy']);
        Route::post('listings/{listing}/submit', [ListingController::class, 'submit']);
        Route::post('listings/{listing}/mark-sold', [ListingController::class, 'markSold']);
        Route::post('listings/{listing}/mark-rented', [ListingController::class, 'markRented']);
        Route::post('listings/{listing}/mark-available', [ListingController::class, 'markAvailable']);

        // Media. Uploads get their own limiter because each one costs disk and
        // validation time, unlike an ordinary JSON write.
        Route::post('listings/{listing}/media', [MediaController::class, 'store'])
            ->middleware('throttle:uploads');
        Route::delete('listings/{listing}/media/{media}', [MediaController::class, 'destroy']);
        Route::post('listings/{listing}/media/reorder', [MediaController::class, 'reorder']);
        Route::post('listings/{listing}/media/{media}/primary', [MediaController::class, 'setPrimary']);

        // Favorites
        Route::get('favorites', [FavoriteController::class, 'index']);
        Route::post('listings/{listing}/favorite', [FavoriteController::class, 'store']);
        Route::delete('listings/{listing}/favorite', [FavoriteController::class, 'destroy']);

        // Business profile (self-service)
        Route::post('businesses', [BusinessController::class, 'storeOrUpdate']);
        Route::put('businesses/service-areas', [BusinessController::class, 'syncServiceAreas']);
        Route::put('businesses/industries', [BusinessController::class, 'syncIndustries']);

        Route::get('users/me/listings', [UserListingController::class, 'index']);
        Route::get('users/me/business', [BusinessController::class, 'mine']);
        Route::get('users/me/products', [ProductController::class, 'mine']);
        Route::get('users/me/services', [ServiceController::class, 'mine']);

        // Products / Services (Business capabilities)
        Route::post('products', [ProductController::class, 'store']);
        Route::patch('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);
        Route::post('services', [ServiceController::class, 'store']);
        Route::patch('services/{service}', [ServiceController::class, 'update']);
        Route::delete('services/{service}', [ServiceController::class, 'destroy']);

        // Leads / CRM (owner inbox)
        Route::get('users/me/leads', [LeadController::class, 'index']);
        Route::patch('leads/{lead}/status', [LeadController::class, 'updateStatus']);

        // Requirement system (Customer -> Requirement -> marketplace finds businesses).
        // GET requirements is the business-facing browse of publicly-visible
        // requirements; users/me/requirements is the customer's own dashboard list.
        Route::get('users/me/requirements', [RequirementController::class, 'index']);
        Route::get('requirements', [RequirementController::class, 'indexPublic']);
        /*
         * A requirement fans out to many suppliers, so a duplicate here wastes real
         * people's time, not just a database row.
         *
         * Order matters: `idempotent` runs before `no-duplicates`. A client that sent
         * an idempotency key has explicitly declared "this is a retry of one intent",
         * and that declaration should be honoured with a replay of the original
         * response. With the order reversed, the retry looks like identical content
         * and gets a 429 instead — the two protections cancel each other out, which
         * is what happened on the first pass.
         */
        Route::post('requirements', [RequirementController::class, 'store'])
            ->middleware(['throttle:requirements', 'idempotent', 'no-duplicates']);
        Route::get('requirements/{requirement}', [RequirementController::class, 'show']);
        Route::patch('requirements/{requirement}', [RequirementController::class, 'update']);
        Route::post('requirements/{requirement}/publish', [RequirementController::class, 'publish']);
        Route::post('requirements/{requirement}/cancel', [RequirementController::class, 'cancel']);

        // Matching engine (Phase 4). The customer reads the businesses matched
        // to their own requirement and can re-run the engine; the business
        // works its invitation inbox. Each side is on its own route so neither
        // can reach the other's view of a match.
        Route::get('requirements/{requirement}/matches', [RequirementMatchController::class, 'indexForRequirement']);
        Route::post('requirements/{requirement}/matches', [RequirementMatchController::class, 'generate']);
        Route::get('users/me/matched-requirements', [RequirementMatchController::class, 'indexForBusiness']);
        Route::get('matches/{match}', [RequirementMatchController::class, 'show']);
        Route::post('matches/{match}/accept', [RequirementMatchController::class, 'accept']);
        Route::post('matches/{match}/decline', [RequirementMatchController::class, 'decline']);
        Route::post('matches/{match}/question', [RequirementMatchController::class, 'question']);

        // Enquiry + quotation engine (Phase 5). Both `enquiries` and `quotes`
        // are role-aware collections: a customer gets their own, a business gets
        // its own, an admin gets everything for moderation. Each single-record
        // action is authorised by EnquiryPolicy / QuotePolicy.
        /*
         * Deliberately NOT carrying `no-duplicates`.
         *
         * This endpoint already de-duplicates at the domain level: `enquiries` has a
         * unique (requirement, business) pair, and a repeat submission returns the
         * existing enquiry with 201 rather than creating a second one.
         *
         * That is a better answer than the middleware's. Content-hash rejection
         * would turn the second click into a 429, so a customer who resubmits gets
         * an error instead of the enquiry they already have — and it broke
         * EnquiryWorkflowTest::test_opening_the_same_enquiry_twice_is_idempotent,
         * which exists precisely to pin that behaviour down.
         *
         * The abuse case the middleware would have covered — one body sprayed at
         * many suppliers — is already handled twice over: the unique pair makes a
         * repeat against the same supplier a no-op, and `throttle:enquiries` caps
         * the fan-out rate.
         */
        Route::post('requirements/{requirement}/enquiries', [EnquiryController::class, 'store'])
            ->middleware(['throttle:enquiries', 'idempotent']);
        Route::get('enquiries', [EnquiryController::class, 'index']);
        Route::get('enquiries/{enquiry}', [EnquiryController::class, 'show']);
        Route::post('enquiries/{enquiry}/accept', [EnquiryController::class, 'accept']);
        Route::post('enquiries/{enquiry}/decline', [EnquiryController::class, 'decline']);
        Route::post('enquiries/{enquiry}/cancel', [EnquiryController::class, 'cancel']);
        Route::post('enquiries/{enquiry}/close', [EnquiryController::class, 'close']);

        Route::get('enquiries/{enquiry}/quotes', [EnquiryController::class, 'quotes']);
        Route::post('enquiries/{enquiry}/quotes', [QuoteController::class, 'store'])
            ->middleware(['throttle:quotes', 'idempotent']);

        Route::get('quotes', [QuoteController::class, 'index']);
        Route::get('quotes/{quote}', [QuoteController::class, 'show']);
        // PATCH matches this project's convention for partial updates; PUT is
        // accepted too so the documented REST shape works as-is.
        Route::match(['put', 'patch'], 'quotes/{quote}', [QuoteController::class, 'update']);
        Route::delete('quotes/{quote}', [QuoteController::class, 'destroy']);
        Route::post('quotes/{quote}/send', [QuoteController::class, 'send']);
        Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept']);
        Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject']);
        Route::post('quotes/{quote}/withdraw', [QuoteController::class, 'withdraw']);

        // Negotiation, counter offers and the immutable revision ledger (Phase 6).
        // A counter is made against the quote (it may be the first one, before a
        // negotiation row exists); everything afterwards addresses the negotiation.
        Route::get('requirements/{requirement}/quotes/comparison', [QuoteComparisonController::class, 'index']);
        Route::post('quotes/{quote}/counter', [NegotiationController::class, 'counter']);
        Route::get('negotiations', [NegotiationController::class, 'index']);
        Route::get('negotiations/{negotiation}', [NegotiationController::class, 'show']);
        Route::post('negotiations/{negotiation}/accept', [NegotiationController::class, 'accept']);
        Route::post('negotiations/{negotiation}/reject', [NegotiationController::class, 'reject']);
        Route::post('negotiations/{negotiation}/withdraw', [NegotiationController::class, 'withdraw']);
        Route::post('negotiations/{negotiation}/messages', [NegotiationController::class, 'message']);

        // Orders (Phase 7). An accepted quote becomes an order automatically via
        // the QuoteAccepted listener; the POST below is an idempotent retry, not a
        // second way in.
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        // Money path. Idempotency is the real protection here — a double-clicked
        // "place order" must not become two orders — and the throttle is a backstop.
        Route::post('quotes/{quote}/order', [OrderController::class, 'storeFromQuote'])
            ->middleware(['throttle:payments', 'idempotent']);
        Route::post('orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::patch('orders/{order}/notes', [OrderController::class, 'updateNotes']);

        // Payments (Phase 8). The customer initiates; the receiving business
        // confirms offline receipt or refunds. No endpoint accepts an amount.
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::get('orders/{order}/payments', [PaymentController::class, 'indexForOrder']);
        // Every payment-mutating endpoint is idempotent. A retried confirm or a
        // double-submitted refund is the most expensive duplicate in the product.
        Route::post('orders/{order}/payments', [PaymentController::class, 'store'])
            ->middleware(['throttle:payments', 'idempotent']);
        Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm'])
            ->middleware(['throttle:payments', 'idempotent']);
        Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])
            ->middleware(['throttle:payments', 'idempotent']);

        // Trust layer (Phase 9). A business requests verification and uploads
        // documents; only an admin grants it. Reviews are earned by completed orders.
        // The matching GET lives above the public `businesses/{slug}` route so the
        // wildcard cannot shadow it — see the note there.
        Route::post('businesses/verification', [BusinessVerificationRequestController::class, 'request']);
        Route::post('businesses/documents', [BusinessVerificationRequestController::class, 'storeDocument'])
            ->middleware('throttle:uploads');
        Route::get('business-documents/{document}/download', [BusinessVerificationRequestController::class, 'downloadDocument']);

        Route::get('reviews', [ReviewController::class, 'index']);
        Route::get('reviews/{review}', [ReviewController::class, 'show']);
        // The hard rule — one review per completed order — is enforced in
        // ReviewService by a unique constraint on order_id. This throttle only
        // stops the endpoint being hammered; it is not what guarantees uniqueness.
        Route::post('orders/{order}/review', [ReviewController::class, 'store'])
            ->middleware(['throttle:reviews', 'idempotent']);
        Route::post('reviews/{review}/response', [ReviewController::class, 'respond'])
            ->middleware('throttle:reviews');

        // Disputes (Phase 10). Either party raises; only an admin decides.
        Route::get('disputes', [DisputeController::class, 'index']);
        Route::get('disputes/{dispute}', [DisputeController::class, 'show']);
        Route::post('orders/{order}/disputes', [DisputeController::class, 'store']);

        /*
         * AI layer (Phase 11+).
         *
         * `ai/status` sits outside the throttle deliberately: the frontend polls it to
         * decide whether to render AI at all, and rate-limiting the "is AI available"
         * check would make the fallback path itself unreliable.
         *
         * Everything that can reach a provider carries `throttle:ai`, a per-user burst
         * limiter registered in AiServiceProvider. It is the second brake — the daily
         * quota in AiUsageTracker is the first, and they catch different abuse shapes.
         */
        Route::get('ai/status', [AiConversationController::class, 'status']);

        // Type-ahead suggestions (Phase 12). Outside the AI throttle because they fire
        // per keystroke and never reach a provider — every suggestion is a database row.
        Route::get('ai/search/suggestions', [AiSearchController::class, 'suggestions']);

        Route::middleware('throttle:ai')->group(function (): void {
            Route::get('ai/conversations', [AiConversationController::class, 'index']);
            Route::post('ai/conversations', [AiConversationController::class, 'store']);
            Route::get('ai/conversations/{conversation}', [AiConversationController::class, 'show']);
            Route::delete('ai/conversations/{conversation}', [AiConversationController::class, 'destroy']);
            Route::post('ai/conversations/{conversation}/messages', [AiConversationController::class, 'sendMessage']);

            // Confirmation of AI-proposed writes. These are the only endpoints through
            // which an AI suggestion becomes a database change, and they need a real
            // authenticated request from the owning user — the model cannot reach them.
            Route::get('ai/actions', [AiActionController::class, 'index']);
            Route::get('ai/actions/{action}', [AiActionController::class, 'show']);
            Route::post('ai/actions/{action}/confirm', [AiActionController::class, 'confirm']);
            Route::post('ai/actions/{action}/reject', [AiActionController::class, 'reject']);

            /*
             * Phase 12. Both call a provider, so both are throttled.
             *
             * Neither writes anything: the requirement builder returns a proposal for the
             * customer to review, and search translates a sentence into filters that the
             * ordinary supplier query then runs.
             */
            Route::post('ai/requirement-builder', [AiRequirementBuilderController::class, 'extract']);
            Route::post('ai/search', [AiSearchController::class, 'search']);

            /*
             * Phase 13 — intelligence on top of existing marketplace data.
             *
             * All read-and-suggest. The match explainer reads scores the deterministic
             * Phase 4 engine already stored; the generators return text a human reviews
             * and submits through the ordinary endpoints. Nothing here writes a domain
             * record, and authorization delegates to the existing policies.
             */
            Route::get('ai/requirements/{requirement}/match-explanation', [AiIntelligenceController::class, 'explainMatches']);
            Route::get('ai/requirements/{requirement}/quote-comparison', [AiIntelligenceController::class, 'compareQuotes']);
            Route::post('ai/listings/generate', [AiIntelligenceController::class, 'generateListing']);
            Route::post('ai/enquiries/{enquiry}/draft-reply', [AiIntelligenceController::class, 'draftEnquiryReply']);
            Route::post('ai/enquiries/{enquiry}/quote-structure', [AiIntelligenceController::class, 'suggestQuoteStructure']);
            Route::post('ai/reviews/{review}/draft-response', [AiIntelligenceController::class, 'draftReviewResponse']);
        });

        // Feedback on an assistant reply. Outside the AI throttle because it reaches no
        // provider, and rate-limiting the "this answer was wrong" button would suppress
        // exactly the signal worth having.
        Route::post('ai/messages/{message}/feedback', [AiFeedbackController::class, 'store']);

        // Admin / moderator moderation queue
        Route::prefix('admin')->group(function (): void {
            Route::get('listings', [AdminListingController::class, 'index']);
            Route::get('listings/{listing}', [AdminListingController::class, 'show']);
            Route::post('listings/{listing}/approve', [AdminListingController::class, 'approve']);
            Route::post('listings/{listing}/reject', [AdminListingController::class, 'reject']);
            Route::post('listings/{listing}/archive', [AdminListingController::class, 'archive']);
            Route::post('businesses/{business}/verify', [BusinessVerificationController::class, 'verify']);

            // Moderation and monitoring (Phases 9-10). Every action here is audited
            // against the acting admin; the monitoring endpoints are read-only.
            Route::get('dashboard', [AdminModerationController::class, 'dashboard']);
            Route::get('users', [AdminModerationController::class, 'users']);
            Route::get('businesses', [AdminModerationController::class, 'businesses']);
            Route::post('businesses/{business}/verification', [AdminModerationController::class, 'updateVerification']);
            Route::get('businesses/{business}/documents', [AdminModerationController::class, 'documents']);
            Route::post('business-documents/{document}/review', [AdminModerationController::class, 'reviewDocument']);
            Route::get('orders', [AdminModerationController::class, 'orders']);
            Route::get('payments', [AdminModerationController::class, 'payments']);
            Route::get('reviews', [AdminModerationController::class, 'reviews']);
            Route::post('reviews/{review}/moderate', [ReviewController::class, 'moderate']);
            Route::get('disputes', [AdminModerationController::class, 'disputes']);
            Route::post('disputes/{dispute}/status', [DisputeController::class, 'updateStatus']);
            Route::get('audit-logs', [AdminModerationController::class, 'auditLogs']);

            // AI usage and spend (Phase 11). Reads the request ledger, which holds no
            // prompt or response text — so a cost report exposes no conversation.
            Route::get('ai/usage', [AiUsageController::class, 'index']);
        });
    });
});
