<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * Orders the caller is a party to.
 *
 * Authorisation reuses `OrderPolicy::partyFor()` — the same method the HTTP
 * controllers call. The AI layer deliberately adds no second permission system: if a
 * rule changes, it changes in one place and this tool inherits it.
 */
class GetOrderStatusTool extends BaseTool
{
    public function __construct(private readonly OrderPolicy $policy) {}

    public function name(): string
    {
        return 'get_order_status';
    }

    public function description(): string
    {
        return 'Get orders the signed-in user is a party to, with status, payment position and outstanding balance. '
            .'Pass order_id for one specific order, or omit it to list recent orders.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'order_id' => $this->stringProperty('Order uuid or order number, for a single order.'),
            'open_only' => ['type' => 'boolean', 'description' => 'Only orders that are not completed or cancelled.'],
            'limit' => $this->integerProperty('Maximum orders to return (default 5).', 1, 20),
        ]);
    }

    public function roles(): array
    {
        return [UserRole::Customer, UserRole::Owner];
    }

    /**
     * Record-level check for the single-order case.
     *
     * The list case needs no check here because the query is scoped by party below;
     * this exists so a specific uuid cannot be probed.
     */
    public function authorize(User $user, array $arguments): bool
    {
        if (empty($arguments['order_id'])) {
            return true;
        }

        $order = $this->findOrder((string) $arguments['order_id']);

        // A non-existent id and an unauthorised one both return false, so the model
        // cannot distinguish "no such order" from "someone else's order" — that
        // difference is an enumeration oracle.
        return $order !== null && $this->policy->partyFor($user, $order) !== null;
    }

    public function handle(User $user, array $arguments): array
    {
        $query = Order::query()
            ->with(['business:id,name,slug', 'requirement:id,title'])
            ->latest('id');

        // Party scoping, mirroring OrderController::index.
        if ($user->isCustomer()) {
            $query->where('customer_id', $user->id);
        } else {
            $business = $user->business;

            if ($business === null) {
                return ['count' => 0, 'orders' => [], 'note' => 'This user has no business profile yet.'];
            }

            $query->where('business_id', $business->id);
        }

        if (! empty($arguments['order_id'])) {
            $reference = (string) $arguments['order_id'];
            $query->where(fn ($q) => $q->where('uuid', $reference)->orWhere('order_number', $reference));
        }

        if (! empty($arguments['open_only'])) {
            $query->open();
        }

        $orders = $query->limit($this->limit($arguments))->get();

        return [
            'count' => $orders->count(),
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->uuid,
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'payment_status' => $order->payment_status->value,
                'fulfilment_status' => $order->fulfilment_status->value,
                'total_amount' => $order->total_amount,
                'amount_paid' => $order->amount_paid,
                // Server-computed, never recalculated by the AI.
                'amount_due' => $order->amountDue(),
                'currency' => $order->currency,
                'counterparty' => $user->isCustomer() ? $order->business?->name : null,
                'requirement' => $order->requirement?->title,
                'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
                'placed_at' => $order->placed_at?->toDateString(),
            ])->all(),
        ];
    }

    private function findOrder(string $reference): ?Order
    {
        return Order::query()
            ->where('uuid', $reference)
            ->orWhere('order_number', $reference)
            ->first();
    }
}
