<?php

namespace App\Providers;

use App\Contracts\AiTool;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\Read\AdminAttentionQueueTool;
use App\Services\Ai\Tools\Read\AdminDemandInsightsTool;
use App\Services\Ai\Tools\Read\AdminMarketplaceOverviewTool;
use App\Services\Ai\Tools\Read\CompareQuotesTool;
use App\Services\Ai\Tools\Read\GetBusinessEnquiriesTool;
use App\Services\Ai\Tools\Read\GetBusinessOpportunitiesTool;
use App\Services\Ai\Tools\Read\GetBusinessProfileTool;
use App\Services\Ai\Tools\Read\GetMarketplaceHelpTool;
use App\Services\Ai\Tools\Read\GetMyQuotesTool;
use App\Services\Ai\Tools\Read\GetMyRequirementsTool;
use App\Services\Ai\Tools\Read\GetOrderStatusTool;
use App\Services\Ai\Tools\Read\ListCategoriesTool;
use App\Services\Ai\Tools\Read\SearchBusinessesTool;
use App\Services\Ai\Tools\Write\CreateRequirementDraftTool;
use App\Services\Ai\Tools\Write\UpdateRequirementDraftTool;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI layer into the container.
 *
 * The tool list here is the complete, explicit inventory of what the AI can do. It is
 * a hand-written array rather than a directory scan on purpose: a new tool becomes
 * callable only when someone adds a line to this file, which means the set of AI
 * capabilities is reviewable in a diff instead of being an emergent property of the
 * filesystem.
 */
class AiServiceProvider extends ServiceProvider
{
    /**
     * Every tool the AI may ever invoke. Order is irrelevant; presence is not.
     *
     * @var array<int, class-string<AiTool>>
     */
    private const TOOLS = [
        // Read — shared
        ListCategoriesTool::class,
        SearchBusinessesTool::class,
        GetBusinessProfileTool::class,
        GetMarketplaceHelpTool::class,

        // Read — customer
        GetMyRequirementsTool::class,
        GetMyQuotesTool::class,
        CompareQuotesTool::class,
        GetOrderStatusTool::class,

        // Read — business owner
        GetBusinessOpportunitiesTool::class,
        GetBusinessEnquiriesTool::class,

        // Read — admin. Deliberately no admin write tool exists: the copilot is
        // read-only, so verifying a business or resolving a dispute is impossible
        // through the AI by construction rather than by policy.
        AdminMarketplaceOverviewTool::class,
        AdminDemandInsightsTool::class,
        AdminAttentionQueueTool::class,

        // Write — confirmation-gated, customer only.
        CreateRequirementDraftTool::class,
        UpdateRequirementDraftTool::class,
    ];

    public function register(): void
    {
        // Singleton so the reachability probe and resolved provider are shared for
        // the request rather than rebuilt per injection.
        $this->app->singleton(AiProviderManager::class);

        $this->app->singleton(ToolRegistry::class, function ($app): ToolRegistry {
            $registry = new ToolRegistry;

            foreach (self::TOOLS as $tool) {
                // Resolved through the container so tools can depend on existing
                // services and policies — reusing them rather than reimplementing
                // authorisation inside the AI layer.
                $registry->register($app->make($tool));
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->registerRateLimiter();
    }

    /**
     * Burst limiter for the AI endpoints.
     *
     * Separate from the daily quota and doing a different job: the quota caps total
     * spend per user per day, this stops a script issuing fifty requests in a minute.
     * Keyed on the user id, falling back to IP for unauthenticated callers — although
     * every AI route requires auth, so the IP branch is defence in depth rather than
     * a live path.
     */
    private function registerRateLimiter(): void
    {
        RateLimiter::for('ai', function (Request $request): Limit {
            $perMinute = (int) config('ai.limits.rate_limit', 12);
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute($perMinute)->by('ai:user:'.$user->id)
                : Limit::perMinute(3)->by('ai:ip:'.$request->ip());
        });
    }
}
