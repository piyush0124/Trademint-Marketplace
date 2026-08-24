<?php

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\ReferenceNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10 — dispute foundation.
 *
 * Scope is deliberately modest, as specified: raise, review, resolve. No arbitration
 * workflow, no evidence vault, no automated refunds. What it does guarantee is that
 * a dispute is always tied to a real order between the two parties, that neither
 * party can resolve their own dispute, and that the original complaint is immutable.
 *
 * Raising a dispute moves the order to Disputed, which the order state machine
 * already accounts for.
 */
class DisputeService
{
    public const RELATIONS = ['order.items', 'business.location', 'customer'];

    public function __construct(
        private readonly ReferenceNumberGenerator $numbers,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    /** Either party raises a dispute on an order they are part of. */
    public function raise(Order $order, User $actor, string $party, string $reason, string $description, ?string $evidence = null): Dispute
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages([
                'order' => ['A cancelled order cannot be disputed.'],
            ]);
        }

        // One active dispute at a time keeps the admin queue honest.
        $active = Dispute::query()->where('order_id', $order->id)->active()->first();

        if ($active !== null) {
            throw ValidationException::withMessages([
                'order' => ['There is already an open dispute on this order.'],
            ]);
        }

        $dispute = DB::transaction(function () use ($order, $actor, $party, $reason, $description, $evidence): Dispute {
            $dispute = $this->numbers->withNumber('DSP', 'disputes', 'reference', function (string $reference) use ($order, $actor, $party, $reason, $description, $evidence): Dispute {
                $dispute = Dispute::create([
                    'reference' => $reference,
                    'order_id' => $order->id,
                    // Parties derived from the order, never from input.
                    'customer_id' => $order->customer_id,
                    'business_id' => $order->business_id,
                    'raised_by_party' => $party,
                    'raised_by_user_id' => $actor->id,
                    'reason' => $reason,
                    'description' => $description,
                    'evidence' => $evidence,
                ]);

                $dispute->forceFill(['status' => DisputeStatus::Open])->save();

                return $dispute;
            });

            // Reflect it on the order, through the guarded transition so the status
            // history records it like any other change.
            if ($order->status->canTransitionTo(OrderStatus::Disputed)) {
                $this->orders->transition($order, OrderStatus::Disputed, $actor, $party, sprintf('Dispute %s raised.', $dispute->reference));
            }

            return $dispute;
        });

        $this->audit->log('dispute.raised', $dispute, $actor, ['reason' => $reason, 'party' => $party]);

        return $dispute->load(self::RELATIONS);
    }

    /** Admin moves the dispute along. Only an admin may. */
    public function updateStatus(Dispute $dispute, User $admin, DisputeStatus $status, ?string $notes = null, ?string $resolution = null): Dispute
    {
        if ($dispute->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => [sprintf('This dispute is already %s.', strtolower($dispute->status->label()))],
            ]);
        }

        $dispute->forceFill([
            'status' => $status,
            'admin_notes' => $notes ?? $dispute->admin_notes,
            'resolution' => $resolution ?? $dispute->resolution,
            'resolved_by_user_id' => $status->isFinal() ? $admin->id : $dispute->resolved_by_user_id,
            'resolved_at' => $status->isFinal() ? now() : null,
        ])->save();

        $this->audit->log('dispute.'.$status->value, $dispute, $admin, ['resolution' => $resolution]);

        return $dispute->fresh(self::RELATIONS);
    }
}
