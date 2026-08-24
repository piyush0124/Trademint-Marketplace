<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\Business;
use App\Models\Dispute;
use App\Models\Review;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * The things actually waiting on a human administrator.
 *
 * Three queues in one tool — verifications, disputes, flagged reviews — because they
 * are the same job (work through what needs a decision) and an admin asking "what
 * needs me today" should not cost three round trips to the model.
 *
 * Each row carries just enough to triage and a reference to open the real record.
 * Deliberately excluded: dispute descriptions, review text, business documents. An
 * admin reads those in the admin UI where they are not forwarded to a third party.
 */
class AdminAttentionQueueTool extends BaseTool
{
    public function name(): string
    {
        return 'admin_attention_queue';
    }

    public function description(): string
    {
        return 'List items awaiting administrator action: businesses pending verification, open or under-review disputes, '
            .'and flagged reviews. Returns references and ages, not full record contents.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'queue' => $this->stringProperty('Which queue to return. Omit for all three.', ['verifications', 'disputes', 'reviews']),
            'limit' => $this->integerProperty('Maximum items per queue (default 10).', 1, 25),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Admin];
    }

    public function handle(User $user, array $arguments): array
    {
        $queue = $arguments['queue'] ?? null;
        $limit = $this->limit($arguments, 10, 25);
        $result = [];

        if ($queue === null || $queue === 'verifications') {
            $pending = Business::query()
                ->where('verification_status', VerificationStatus::Pending->value)
                ->withCount('documents')
                ->orderBy('verification_requested_at')
                ->limit($limit)
                ->get();

            $result['verifications'] = [
                'total' => Business::where('verification_status', VerificationStatus::Pending->value)->count(),
                'items' => $pending->map(fn (Business $business): array => [
                    'business' => $business->name,
                    'slug' => $business->slug,
                    'documents_uploaded' => (int) $business->documents_count,
                    'requested_at' => $business->verification_requested_at?->toDateString(),
                    'waiting_days' => $business->verification_requested_at?->diffInDays(now()),
                ])->all(),
            ];
        }

        if ($queue === null || $queue === 'disputes') {
            $open = Dispute::query()
                ->active()
                ->with(['order:id,order_number,total_amount,currency', 'business:id,name'])
                ->orderBy('created_at')
                ->limit($limit)
                ->get();

            $result['disputes'] = [
                'total' => Dispute::query()->active()->count(),
                'items' => $open->map(fn (Dispute $dispute): array => [
                    'reference' => $dispute->reference,
                    'status' => $dispute->status->value,
                    // The reason key, not the complainant's free text.
                    'reason' => $dispute->reason,
                    'raised_by' => $dispute->raised_by_party,
                    'order_number' => $dispute->order?->order_number,
                    'order_value' => $dispute->order?->total_amount,
                    'currency' => $dispute->order?->currency,
                    'business' => $dispute->business?->name,
                    'waiting_days' => $dispute->created_at?->diffInDays(now()),
                ])->all(),
            ];
        }

        if ($queue === null || $queue === 'reviews') {
            $flagged = Review::query()
                ->where('status', 'flagged')
                ->with('business:id,name')
                ->orderBy('created_at')
                ->limit($limit)
                ->get();

            $result['reviews'] = [
                'total' => Review::query()->where('status', 'flagged')->count(),
                'items' => $flagged->map(fn (Review $review): array => [
                    'id' => $review->uuid,
                    'business' => $review->business?->name,
                    'rating' => (int) $review->rating,
                    'flagged_at' => $review->moderated_at?->toDateString(),
                ])->all(),
            ];
        }

        $result['note'] = 'References only. Open the record in the admin UI to read its full contents.';

        return $result;
    }
}
