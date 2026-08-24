<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AI usage and cost reporting for administrators.
 *
 * Reads the `ai_requests` ledger, which holds no prompt or response text — so this
 * report can be handed to whoever owns the budget without also handing them
 * customers' requirements. That separation was the reason for keeping content out of
 * the ledger in the first place.
 */
class AiUsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'You do not have permission to perform this action.');

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days = (int) ($validated['days'] ?? 7);
        $since = now()->subDays($days)->startOfDay();

        $window = fn (): Builder => AiRequest::query()->where('created_at', '>=', $since);

        return response()->json([
            'success' => true,
            'message' => 'AI usage retrieved successfully.',
            'data' => [
                'window_days' => $days,
                'totals' => [
                    'requests' => $window()->count(),
                    'successful' => $window()->where('status', AiRequestStatus::Success->value)->count(),
                    'failed' => $window()->whereNotIn('status', [
                        AiRequestStatus::Success->value,
                        AiRequestStatus::Disabled->value,
                    ])->count(),
                    'tokens' => (int) $window()->sum('total_tokens'),
                    // Zero on a local-Ollama deployment, which is truthful rather
                    // than a placeholder — local inference genuinely costs nothing.
                    'estimated_cost' => (string) round((float) $window()->sum('estimated_cost'), 4),
                    'average_latency_ms' => (int) round((float) $window()->whereNotNull('latency_ms')->avg('latency_ms')),
                ],
                // Which product surfaces are actually spending the budget. This is the
                // report that tells you a feature needs a cheaper model or a cache.
                'by_feature' => $window()
                    ->select('feature', DB::raw('count(*) as requests'), DB::raw('sum(total_tokens) as tokens'), DB::raw('sum(estimated_cost) as cost'), DB::raw('avg(latency_ms) as avg_latency'))
                    ->groupBy('feature')
                    ->orderByDesc('requests')
                    ->get()
                    ->map(fn ($row): array => [
                        'feature' => $row->feature,
                        'requests' => (int) $row->requests,
                        'tokens' => (int) $row->tokens,
                        'estimated_cost' => (string) round((float) $row->cost, 4),
                        'average_latency_ms' => (int) round((float) $row->avg_latency),
                    ])->all(),
                'by_role' => $window()
                    ->select('actor_role', DB::raw('count(*) as requests'), DB::raw('sum(total_tokens) as tokens'))
                    ->groupBy('actor_role')
                    ->get()
                    ->map(fn ($row): array => [
                        'role' => $row->actor_role ?? 'system',
                        'requests' => (int) $row->requests,
                        'tokens' => (int) $row->tokens,
                    ])->all(),
                'by_status' => $window()
                    ->select('status', DB::raw('count(*) as requests'))
                    ->groupBy('status')
                    ->get()
                    ->map(fn ($row): array => ['status' => $row->status, 'requests' => (int) $row->requests])
                    ->all(),
                // Heaviest users, by id rather than name — a cost report does not need
                // to name individuals to show a distribution.
                'top_users' => $window()
                    ->whereNotNull('user_id')
                    ->select('user_id', 'actor_role', DB::raw('count(*) as requests'), DB::raw('sum(total_tokens) as tokens'))
                    ->groupBy('user_id', 'actor_role')
                    ->orderByDesc('requests')
                    ->limit(10)
                    ->get()
                    ->map(fn ($row): array => [
                        'user_id' => (int) $row->user_id,
                        'role' => $row->actor_role,
                        'requests' => (int) $row->requests,
                        'tokens' => (int) $row->tokens,
                    ])->all(),
            ],
        ]);
    }
}
