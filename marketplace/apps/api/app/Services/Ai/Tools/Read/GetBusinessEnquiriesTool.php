<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\EnquiryStatus;
use App\Enums\UserRole;
use App\Models\Enquiry;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * Enquiries addressed to the caller's business.
 *
 * Answers the question a business owner actually has each morning — "what is waiting
 * on me?" — so pending enquiries sort first and each row carries how long it has been
 * waiting. That figure is computed from the stored timestamp, not estimated by the
 * model.
 */
class GetBusinessEnquiriesTool extends BaseTool
{
    public function name(): string
    {
        return 'get_business_enquiries';
    }

    public function description(): string
    {
        return 'List enquiries sent to the signed-in business, pending ones first, with how many days each has been waiting. '
            .'Use for "what needs my response" questions.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'status' => $this->stringProperty('Filter by enquiry status.', array_column(EnquiryStatus::cases(), 'value')),
            'limit' => $this->integerProperty('Maximum enquiries to return (default 8).', 1, 20),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Owner];
    }

    public function handle(User $user, array $arguments): array
    {
        $business = $user->business;

        if ($business === null) {
            return ['count' => 0, 'enquiries' => [], 'note' => 'Create a business profile to receive enquiries.'];
        }

        $query = Enquiry::query()
            ->where('business_id', $business->id)
            ->with(['requirement:id,uuid,title,quantity,unit,budget_min,budget_max,category_id,location_id', 'requirement.category:id,name', 'requirement.location:id,city'])
            // Pending first, then oldest — the queue a person would work.
            ->orderByRaw('status = ? desc', [EnquiryStatus::Pending->value])
            ->orderBy('created_at');

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        $enquiries = $query->limit($this->limit($arguments, 8))->get();

        return [
            'count' => $enquiries->count(),
            'pending_count' => $enquiries->where('status', EnquiryStatus::Pending)->count(),
            'enquiries' => $enquiries->map(fn (Enquiry $enquiry): array => [
                'id' => $enquiry->uuid,
                'status' => $enquiry->status->value,
                'requirement_title' => $enquiry->requirement?->title,
                'requirement_id' => $enquiry->requirement?->uuid,
                'category' => $enquiry->requirement?->category?->name,
                'city' => $enquiry->requirement?->location?->city,
                'quantity' => $enquiry->requirement?->quantity,
                'unit' => $enquiry->requirement?->unit,
                'budget_min' => $enquiry->requirement?->budget_min,
                'budget_max' => $enquiry->requirement?->budget_max,
                // The buyer's own words — untrusted content. The assistant fences it
                // before any prompt sees it, and it is never treated as instruction.
                'customer_message' => $enquiry->customer_message,
                'waiting_days' => $enquiry->created_at?->diffInDays(now()),
                'created_at' => $enquiry->created_at?->toDateString(),
            ])->all(),
        ];
    }
}
