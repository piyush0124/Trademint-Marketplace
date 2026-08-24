<?php

namespace App\Services;

use App\Enums\NegotiationParty;
use App\Enums\NegotiationStatus;
use App\Enums\QuoteStatus;
use App\Events\NegotiationAccepted;
use App\Events\NegotiationCountered;
use App\Events\NegotiationRejected;
use App\Events\NegotiationWithdrawn;
use App\Events\QuoteAccepted;
use App\Models\Negotiation;
use App\Models\NegotiationMessage;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\User;
use App\Services\Quotes\QuoteCalculator;
use App\Services\Quotes\QuoteRevisionRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — negotiation, counter offers and revision history.
 *
 * The state machine in one paragraph: a sent quote carries revision 1, authored
 * by the business. Whoever did *not* author the current revision may counter it,
 * accept it, or reject it. Countering appends a new immutable revision and flips
 * the turn. Accepting settles both the negotiation and the quote. Nothing can be
 * done once the negotiation is final — which is how "accepted → counter offer"
 * is prevented, structurally rather than by a special case.
 *
 * Safety properties, all enforced here and covered by tests:
 *
 *  - Turn discipline: acting out of turn is refused (403 via policy, 422 here).
 *  - Optimistic concurrency: a counter/decision must name the revision it is
 *    responding to. If that is no longer current, the request is rejected 409 —
 *    so two simultaneous counters cannot both land.
 *  - Money is always recalculated by QuoteCalculator from line inputs; a total
 *    in the request body is ignored, exactly as in Phase 5.
 *  - Expiry is evaluated against the current revision on every action.
 *  - Revisions are immutable at the model level; history is never rewritten.
 */
class NegotiationService
{
    public const RELATIONS = [
        'quote.items',
        'quote.business.location',
        'requirement.category',
        'requirement.location',
        'currentRevision.items',
        'messages.revision',
    ];

    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly QuoteRevisionRecorder $recorder,
    ) {}

    /**
     * A party proposes different terms.
     *
     * @param  array<string, mixed>  $data  validated counter payload (items + charges + terms)
     */
    public function counter(Quote $quote, User $actor, NegotiationParty $party, array $data): Negotiation
    {
        // Calculated before the transaction: a validation failure here should not
        // leave a half-open negotiation behind.
        $items = $data['items'];
        $calculated = $this->calculator->calculate($items, $data);

        $this->settleExpiryBeforeAction($quote);

        $negotiation = DB::transaction(function () use ($quote, $actor, $party, $data, $items, $calculated): Negotiation {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $negotiation = $this->openFor($locked, $party);
            $this->assertActionable($negotiation, $party, $data['revision_id'] ?? null);

            $revision = $this->recorder->record(
                quote: $locked,
                calculated: $calculated,
                itemInputs: $items,
                authoredBy: $party,
                author: $actor,
                revisionType: 'counter',
                terms: [
                    'currency' => $locked->currency,
                    'validity_date' => $data['validity_date'] ?? null,
                    'estimated_delivery_date' => $data['estimated_delivery_date'] ?? null,
                    'estimated_completion_date' => $data['estimated_completion_date'] ?? null,
                    'payment_terms' => $data['payment_terms'] ?? null,
                    'warranty_terms' => $data['warranty_terms'] ?? null,
                    'cancellation_terms' => $data['cancellation_terms'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'message' => $data['message'] ?? null,
                    'is_final_offer' => $data['is_final_offer'] ?? false,
                ],
            );

            $this->recorder->syncQuoteToRevision($locked, $revision);

            // The quote is now in negotiation rather than simply "sent"; the
            // customer can still accept it at any point.
            $locked->status = QuoteStatus::Negotiating;
            $locked->save();

            $negotiation->status = NegotiationStatus::Countered;
            $negotiation->current_revision_id = $revision->id;
            // The turn passes to the other side.
            $negotiation->awaiting_party = $party->counterparty();
            $negotiation->round = $revision->revision_number;
            $negotiation->last_activity_at = now();
            $negotiation->save();

            $this->log($negotiation, $actor, $party, 'counter', $data['message'] ?? null, $revision);

            return $negotiation;
        });

        NegotiationCountered::dispatch($negotiation);

        return $negotiation->fresh(self::RELATIONS);
    }

    /**
     * The party being offered to accepts the current revision.
     *
     * Serialised under a row lock and guarded by the revision check, so a
     * double-click or a retried request cannot produce two acceptances.
     */
    public function accept(Quote $quote, User $actor, NegotiationParty $party, ?string $revisionId = null, ?string $note = null): Negotiation
    {
        $this->settleExpiryBeforeAction($quote);

        $negotiation = DB::transaction(function () use ($quote, $actor, $party, $revisionId, $note): Negotiation {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $negotiation = $this->requireNegotiation($locked);
            $this->assertActionable($negotiation, $party, $revisionId);

            $revision = $negotiation->currentRevision;

            $negotiation->status = NegotiationStatus::Accepted;
            $negotiation->awaiting_party = null;
            $negotiation->accepted_at = now();
            $negotiation->outcome_note = $note;
            $negotiation->last_activity_at = now();
            $negotiation->save();

            // The accepted revision is what an order (Phase 7) is formed from.
            $locked->status = QuoteStatus::Accepted;
            $locked->accepted_at = now();
            $locked->viewed_at ??= now();
            $locked->save();

            $this->log($negotiation, $actor, $party, 'accept', $note, $revision);

            return $negotiation;
        });

        NegotiationAccepted::dispatch($negotiation);
        // The Phase 5/7 hand-off: an accepted negotiation is an accepted quote.
        QuoteAccepted::dispatch($negotiation->quote->fresh());

        return $negotiation->fresh(self::RELATIONS);
    }

    /** The party being offered to declines. The history is kept intact. */
    public function reject(Quote $quote, User $actor, NegotiationParty $party, ?string $reason = null): Negotiation
    {
        $negotiation = DB::transaction(function () use ($quote, $actor, $party, $reason): Negotiation {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $negotiation = $this->requireNegotiation($locked);

            // Rejection is allowed even on a lapsed offer — you may always walk
            // away — so expiry is not asserted here, only turn and finality.
            $this->assertParticipantTurn($negotiation, $party);

            $negotiation->status = NegotiationStatus::Rejected;
            $negotiation->awaiting_party = null;
            $negotiation->rejected_at = now();
            $negotiation->outcome_note = $reason;
            $negotiation->last_activity_at = now();
            $negotiation->save();

            // A customer rejecting kills the quote; a business rejecting the
            // customer's counter leaves its own last offer rejected too — either
            // way the commercial thread is closed and preserved.
            $locked->status = QuoteStatus::Rejected;
            $locked->rejected_at = now();
            $locked->rejection_reason = $reason;
            $locked->save();

            $this->log($negotiation, $actor, $party, 'reject', $reason, $negotiation->currentRevision);

            return $negotiation;
        });

        NegotiationRejected::dispatch($negotiation);

        return $negotiation->fresh(self::RELATIONS);
    }

    /**
     * The party that made the current offer pulls it back.
     *
     * The mirror image of accept/reject: only the *offering* side may withdraw,
     * and only while the other side has not yet responded.
     */
    public function withdraw(Quote $quote, User $actor, NegotiationParty $party, ?string $reason = null): Negotiation
    {
        $negotiation = DB::transaction(function () use ($quote, $actor, $party, $reason): Negotiation {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $negotiation = $this->requireNegotiation($locked);

            if (! $negotiation->isActive()) {
                throw ValidationException::withMessages([
                    'status' => [sprintf('This negotiation is already %s.', strtolower($negotiation->status->label()))],
                ]);
            }

            $current = $negotiation->currentRevision;

            if ($current === null || ! $current->authoredBy($party)) {
                throw ValidationException::withMessages([
                    'status' => ['You can only withdraw an offer you made yourself.'],
                ]);
            }

            $negotiation->status = NegotiationStatus::Withdrawn;
            $negotiation->awaiting_party = null;
            $negotiation->withdrawn_at = now();
            $negotiation->outcome_note = $reason;
            $negotiation->last_activity_at = now();
            $negotiation->save();

            $locked->status = QuoteStatus::Withdrawn;
            $locked->withdrawn_at = now();
            $locked->save();

            $this->log($negotiation, $actor, $party, 'withdraw', $reason, $current);

            return $negotiation;
        });

        NegotiationWithdrawn::dispatch($negotiation);

        return $negotiation->fresh(self::RELATIONS);
    }

    /** A plain message on the timeline. Does not move the state or the turn. */
    public function message(Negotiation $negotiation, User $actor, NegotiationParty $party, string $body): NegotiationMessage
    {
        if ($negotiation->status->isFinal()) {
            throw ValidationException::withMessages([
                'body' => ['This negotiation is closed.'],
            ]);
        }

        $entry = $this->log($negotiation, $actor, $party, 'message', $body, $negotiation->currentRevision);

        $negotiation->last_activity_at = now();
        $negotiation->save();

        return $entry;
    }

    /**
     * Relabel a negotiation whose live offer has lapsed. Called on read and
     * before every action, so expiry needs no scheduler to be correct.
     */
    public function expireIfLapsed(Negotiation $negotiation): bool
    {
        if ($negotiation->status === NegotiationStatus::Expired) {
            return true;
        }

        if (! $negotiation->hasExpired()) {
            return false;
        }

        $negotiation->status = NegotiationStatus::Expired;
        $negotiation->awaiting_party = null;
        $negotiation->save();

        $this->log($negotiation, null, null, 'system', 'The live offer expired.', $negotiation->currentRevision);

        return true;
    }

    /**
     * Find or open the negotiation for a quote. Opening is lazy: a quote that is
     * simply accepted without haggling never needs one.
     */
    public function openFor(Quote $quote, NegotiationParty $initiatedBy): Negotiation
    {
        $existing = Negotiation::query()->where('quote_id', $quote->id)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        $quote->loadMissing('currentRevision');

        return Negotiation::create([
            'quote_id' => $quote->id,
            // Never from request input — derived from the quote itself.
            'requirement_id' => $quote->requirement_id,
            'customer_id' => $quote->customer_id,
            'business_id' => $quote->business_id,
            'status' => NegotiationStatus::Open,
            'initiated_by' => $initiatedBy,
            // The opening offer belongs to the business, so the first turn is
            // the customer's unless the business is the one countering.
            'awaiting_party' => $quote->currentRevision?->awaitingParty() ?? NegotiationParty::Customer,
            'round' => (int) $quote->revision_count,
            'current_revision_id' => $quote->current_revision_id,
            'last_activity_at' => now(),
        ]);
    }

    private function requireNegotiation(Quote $quote): Negotiation
    {
        $negotiation = Negotiation::query()->where('quote_id', $quote->id)->lockForUpdate()->first();

        if ($negotiation === null) {
            throw ValidationException::withMessages([
                'negotiation' => ['There is no negotiation on this quote yet.'],
            ]);
        }

        return $negotiation;
    }

    /**
     * The full guard for any state-changing action: finality, turn, expiry and
     * optimistic concurrency, in that order.
     */
    private function assertActionable(Negotiation $negotiation, NegotiationParty $party, ?string $revisionId): void
    {
        $this->assertParticipantTurn($negotiation, $party);

        // Read-only check. Relabelling happens in settleExpiryBeforeAction(),
        // outside the transaction — writing it here would be undone by the very
        // rollback this exception triggers, stranding a lapsed offer as live.
        if ($negotiation->hasExpired()) {
            throw ValidationException::withMessages([
                'status' => ['The current offer has expired. A new quote is needed.'],
            ]);
        }

        $this->assertRevisionIsCurrent($negotiation, $revisionId);
    }

    /**
     * Settle a lapsed offer *before* any transaction opens, so the relabelling
     * survives the 422 that follows it.
     *
     * Handles the pre-negotiation case too: the very first counter is made
     * against the quote's opening revision, which can itself have expired while
     * the customer was reading it.
     */
    private function settleExpiryBeforeAction(Quote $quote): void
    {
        $negotiation = Negotiation::query()->where('quote_id', $quote->id)->first();

        if ($negotiation !== null) {
            if ($this->expireIfLapsed($negotiation)) {
                throw ValidationException::withMessages([
                    'status' => ['The current offer has expired. A new quote is needed.'],
                ]);
            }

            return;
        }

        $quote->loadMissing('currentRevision');

        if ($quote->currentRevision !== null && $quote->currentRevision->hasExpired()) {
            throw ValidationException::withMessages([
                'status' => ['This quote has expired and can no longer be negotiated.'],
            ]);
        }
    }

    private function assertParticipantTurn(Negotiation $negotiation, NegotiationParty $party): void
    {
        if ($negotiation->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => [sprintf('This negotiation is already %s and cannot be changed.', strtolower($negotiation->status->label()))],
            ]);
        }

        if ($negotiation->awaiting_party !== $party) {
            throw ValidationException::withMessages([
                'status' => ['It is not your turn — the other party is yet to respond to the current offer.'],
            ]);
        }
    }

    /**
     * Optimistic concurrency. The client tells us which offer it is responding
     * to; if that is no longer the live one, somebody moved first and the request
     * is refused with 409 rather than silently applied to a different offer.
     */
    private function assertRevisionIsCurrent(Negotiation $negotiation, ?string $revisionId): void
    {
        if ($revisionId === null) {
            return;
        }

        $current = $negotiation->currentRevision;

        if ($current === null || $current->uuid !== $revisionId) {
            abort(409, 'This offer has already been superseded. Reload the negotiation and try again.');
        }
    }

    private function log(
        Negotiation $negotiation,
        ?User $actor,
        ?NegotiationParty $party,
        string $entryType,
        ?string $body,
        ?QuoteRevision $revision,
    ): NegotiationMessage {
        return $negotiation->messages()->create([
            'quote_revision_id' => $revision?->id,
            'user_id' => $actor?->id,
            'author_party' => $party?->value ?? 'system',
            'entry_type' => $entryType,
            'body' => $body,
        ]);
    }
}
