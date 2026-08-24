<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RequirementStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\Business;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Requirement;
use App\Models\Review;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * Platform-wide counts for the admin copilot.
 *
 * Aggregates only. This tool cannot reach an individual customer's requirement text,
 * a quote's line items or anyone's contact details — an admin has legitimate access
 * to those through the admin UI, but there is no reason to stream them to an AI
 * provider to answer "how many verifications are pending".
 *
 * That is the privacy principle for the admin copilot generally: authorisation says
 * an admin *may* see something; minimisation decides whether the AI *needs* to.
 */
class AdminMarketplaceOverviewTool extends BaseTool
{
    public function name(): string
    {
        return 'admin_marketplace_overview';
    }

    public function description(): string
    {
        return 'Platform-wide counts for administrators: users, suppliers, verification queue, requirements, quotes, '
            .'orders, settled payments, reviews, open disputes. Aggregates only, no personal data. '
            .'Optionally scope to a recent window in days.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'days' => $this->integerProperty('Restrict activity counts to the last N days. Omit for all time.', 1, 365),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Admin];
    }

    public function handle(User $user, array $arguments): array
    {
        $since = isset($arguments['days']) && $arguments['days'] !== null
            ? now()->subDays((int) $arguments['days'])
            : null;

        // Applied only to activity counts. Scoping "total suppliers" by a window
        // would answer a different question than the one asked.
        $window = fn ($query) => $since === null ? $query : $query->where('created_at', '>=', $since);

        return [
            'window_days' => $since === null ? null : (int) $arguments['days'],
            'users' => [
                'total' => User::count(),
                'customers' => User::where('role', UserRole::Customer->value)->count(),
                'business_owners' => User::where('role', UserRole::Owner->value)->count(),
            ],
            'businesses' => [
                'total' => Business::count(),
                'verified' => Business::where('verification_status', VerificationStatus::Verified->value)->count(),
                'pending_verification' => Business::where('verification_status', VerificationStatus::Pending->value)->count(),
                'suspended' => Business::where('verification_status', VerificationStatus::Suspended->value)->count(),
            ],
            'activity' => [
                'requirements' => $window(Requirement::query())->count(),
                'requirements_published' => $window(Requirement::query())
                    ->whereNotIn('status', [RequirementStatus::Draft->value])->count(),
                'quotes' => $window(Quote::query())->count(),
                'orders' => $window(Order::query())->count(),
                'orders_completed' => $window(Order::query())->where('status', OrderStatus::Completed->value)->count(),
                'orders_cancelled' => $window(Order::query())->where('status', OrderStatus::Cancelled->value)->count(),
                'reviews' => $window(Review::query())->count(),
            ],
            'money' => [
                'settled_payments' => $window(Payment::query()->settled())->count(),
                'failed_payments' => $window(Payment::query())->where('status', PaymentStatus::Failed->value)->count(),
                // A naive sum across currencies would be wrong, so the currencies in
                // play are reported alongside it and the model is told to say so.
                'paid_total' => (string) $window(Payment::query()->settled())->sum('amount'),
                'currency' => config('marketplace.default_currency'),
                'currencies_in_use' => Payment::query()->distinct()->pluck('currency')->values()->all(),
            ],
            'attention' => [
                'open_disputes' => Dispute::query()->active()->count(),
                'flagged_reviews' => Review::query()->where('status', 'flagged')->count(),
                'pending_verifications' => Business::where('verification_status', VerificationStatus::Pending->value)->count(),
            ],
        ];
    }
}
