<?php

namespace App\Services;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Events\OrderCancelled;
use App\Events\OrderCompleted;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\User;
use App\Services\Orders\ReferenceNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7 — order creation and lifecycle.
 *
 * Creation rules:
 *  - Only an accepted quote becomes an order.
 *  - Pricing is copied from the accepted *revision*, never from a request, so the
 *    order total always matches the terms both parties agreed to.
 *  - Exactly one order per quote. The unique index enforces it; this service
 *    makes a duplicate attempt return the existing order instead of erroring, so a
 *    retried request or a re-fired event is harmless.
 *
 * Lifecycle rules:
 *  - Transitions are validated against OrderStatus::allowedTransitions(), so an
 *    order cannot jump backwards or skip into an illegal state.
 *  - Each side controls its own part: the business fulfils, the customer confirms
 *    completion or cancels early, an admin can settle a dispute.
 *  - Every change writes an immutable history row with who and why.
 */
class OrderService
{
    public const RELATIONS = ['items', 'business.location', 'requirement.category', 'requirement.location', 'quote', 'statusHistory'];

    public function __construct(private readonly ReferenceNumberGenerator $numbers) {}

    /**
     * Create the order for an accepted quote.
     *
     * Idempotent by design — safe to call from the QuoteAccepted listener, from an
     * explicit endpoint, and from a retry of either.
     */
    public function createFromQuote(Quote $quote): Order
    {
        $quote->loadMissing(['currentRevision.items', 'items']);

        if ($quote->status !== QuoteStatus::Accepted) {
            throw ValidationException::withMessages([
                'quote' => ['Only an accepted quote can become an order.'],
            ]);
        }

        $existing = Order::query()->where('quote_id', $quote->id)->first();

        if ($existing !== null) {
            return $existing->load(self::RELATIONS);
        }

        $order = DB::transaction(function () use ($quote): Order {
            // Lock on the quote so two concurrent acceptances/retries serialise
            // rather than both attempting to insert.
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $again = Order::query()->where('quote_id', $locked->id)->first();
            if ($again !== null) {
                return $again;
            }

            $revision = $locked->currentRevision;

            return $this->numbers->withNumber('ORD', 'orders', 'order_number', function (string $orderNumber) use ($locked, $revision): Order {
                $order = new Order([
                    'quote_id' => $locked->id,
                    'quote_revision_id' => $revision?->id,
                    // Parties come from the quote, never from input.
                    'requirement_id' => $locked->requirement_id,
                    'customer_id' => $locked->customer_id,
                    'business_id' => $locked->business_id,
                    'currency' => $revision->currency ?? $locked->currency,
                    'expected_delivery_date' => $revision->estimated_delivery_date ?? $locked->estimated_delivery_date,
                    'expected_completion_date' => $revision->estimated_completion_date ?? $locked->estimated_completion_date,
                ]);

                $order->order_number = $orderNumber;
                $order->status = OrderStatus::Pending;
                $order->fulfilment_status = FulfilmentStatus::NotStarted;
                $order->placed_at = now();

                // Money copied from the agreed revision (falling back to the quote
                // for a quote accepted before Phase 6 existed).
                $order->forceFill([
                    'subtotal' => $revision->subtotal ?? $locked->subtotal,
                    'tax_amount' => $revision->tax_amount ?? $locked->tax_amount,
                    'delivery_fee' => $revision->delivery_fee ?? $locked->delivery_fee,
                    'discount_amount' => $revision->discount_amount ?? $locked->discount_amount,
                    'other_charges' => $revision->other_charges ?? $locked->other_charges,
                    'total_amount' => $revision->total_amount ?? $locked->total_amount,
                ])->save();

                $this->copyItems($order, $revision, $locked);

                $order->statusHistory()->create([
                    'from_status' => null,
                    'to_status' => OrderStatus::Pending,
                    'changed_by_party' => 'system',
                    'note' => sprintf('Order created from accepted quote %s.', $locked->quote_number),
                ]);

                return $order;
            });
        });

        OrderPlaced::dispatch($order);

        return $order->load(self::RELATIONS);
    }

    /**
     * Move an order to a new status.
     *
     * The actor's party is derived from the order, and the permitted target set
     * differs per party — the guard that stops a business marking its own order
     * "completed" on the customer's behalf.
     */
    public function transition(Order $order, OrderStatus $target, User $actor, string $party, ?string $note = null): Order
    {
        $updated = DB::transaction(function () use ($order, $target, $actor, $party, $note): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            if ($from === $target) {
                throw ValidationException::withMessages([
                    'status' => [sprintf('This order is already %s.', strtolower($target->label()))],
                ]);
            }

            if (! $from->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => [sprintf('An order cannot move from %s to %s.', strtolower($from->label()), strtolower($target->label()))],
                ]);
            }

            $this->assertPartyMaySet($party, $target, $locked);

            $locked->status = $target;
            // Fulfilment is derived, not separately settable, so the two can
            // never contradict each other.
            $locked->fulfilment_status = FulfilmentStatus::forOrderStatus($target);

            if ($target === OrderStatus::Confirmed) {
                $locked->confirmed_at = now();
            }
            if ($target === OrderStatus::Completed) {
                $locked->completed_at = now();
            }
            if ($target === OrderStatus::Cancelled) {
                $locked->cancelled_at = now();
                $locked->cancellation_reason = $note;
            }

            $locked->save();

            $locked->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $target,
                'changed_by_user_id' => $actor->id,
                'changed_by_party' => $party,
                'note' => $note,
            ]);

            return $locked;
        });

        OrderStatusChanged::dispatch($updated);

        if ($updated->status === OrderStatus::Completed) {
            OrderCompleted::dispatch($updated);
        }
        if ($updated->status === OrderStatus::Cancelled) {
            OrderCancelled::dispatch($updated);
        }

        return $updated->load(self::RELATIONS);
    }

    /** Notes are the one thing each side may edit on a live order. */
    public function updateNotes(Order $order, string $party, ?string $note): Order
    {
        if ($party === 'customer') {
            $order->customer_notes = $note;
        } elseif ($party === 'business') {
            $order->business_notes = $note;
        }

        $order->save();

        return $order->load(self::RELATIONS);
    }

    /**
     * Who may set what.
     *
     * Cancellation is the interesting case: a customer may only cancel before work
     * starts, a business up to confirmation. Beyond that the route is a dispute,
     * so neither party can unilaterally walk away from committed work.
     */
    private function assertPartyMaySet(string $party, OrderStatus $target, Order $order): void
    {
        if ($party === 'admin') {
            return;
        }

        if ($target === OrderStatus::Cancelled) {
            $allowed = $party === 'customer'
                ? $order->isCancellableByCustomer()
                : $order->isCancellableByBusiness();

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'status' => ['This order can no longer be cancelled. Raise a dispute instead.'],
                ]);
            }

            return;
        }

        if ($target === OrderStatus::Completed && $party !== 'customer') {
            throw ValidationException::withMessages([
                'status' => ['Only the customer can mark an order complete.'],
            ]);
        }

        if ($party === 'business' && ! in_array($target, OrderStatus::businessControlled(), true)) {
            throw ValidationException::withMessages([
                'status' => ['A business cannot set that status.'],
            ]);
        }

        if ($party === 'customer' && ! in_array($target, OrderStatus::customerControlled(), true)) {
            throw ValidationException::withMessages([
                'status' => ['A customer cannot set that status.'],
            ]);
        }
    }

    /** Copy the agreed lines onto the order, preferring the revision snapshot. */
    private function copyItems(Order $order, ?QuoteRevision $revision, Quote $quote): void
    {
        $lines = $revision !== null && $revision->items->isNotEmpty() ? $revision->items : $quote->items;

        foreach ($lines as $index => $line) {
            $order->items()->create([
                'sort_order' => $line->sort_order ?? $index,
                'description' => $line->description,
                'item_type' => $line->item_type,
                'reference_type' => $line->reference_type,
                'reference_id' => $line->reference_id,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount,
                'discount_amount' => $line->discount_amount,
                'subtotal' => $line->subtotal,
                'total' => $line->total,
            ]);
        }
    }
}
