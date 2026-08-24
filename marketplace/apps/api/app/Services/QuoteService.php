<?php

namespace App\Services;

use App\Enums\NegotiationParty;
use App\Enums\QuoteItemType;
use App\Enums\QuoteStatus;
use App\Events\QuoteAccepted;
use App\Events\QuoteExpired;
use App\Events\QuoteRejected;
use App\Events\QuoteSent;
use App\Events\QuoteViewed;
use App\Events\QuoteWithdrawn;
use App\Models\Enquiry;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service as BusinessService;
use App\Models\User;
use App\Services\Quotes\QuoteCalculator;
use App\Services\Quotes\QuoteNumberGenerator;
use App\Services\Quotes\QuoteRevisionRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 — quotation workflow.
 *
 * Rules this service is responsible for enforcing, none of which are trusted
 * to the client or the frontend:
 *
 *  - Only an accepted enquiry can be quoted.
 *  - Every monetary figure is computed from the line inputs (QuoteCalculator);
 *    a subtotal/tax/total supplied in a request is ignored outright.
 *  - Only a draft can be edited or deleted. Once sent, the commercial content
 *    is frozen — the customer may be relying on it.
 *  - Expiry is evaluated server-side against expires_at, so an out-of-date
 *    frontend cannot accept a lapsed quote.
 *  - Acceptance is serialised with a row lock inside a transaction, so two
 *    simultaneous accepts cannot both succeed.
 *
 * Order creation is explicitly *not* here: acceptance records the decision and
 * fires QuoteAccepted, which is the extension point Phase 7 will hook.
 */
class QuoteService
{
    public const RELATIONS = ['items', 'business.location', 'requirement.category', 'requirement.location', 'enquiry'];

    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly QuoteNumberGenerator $numbers,
        private readonly QuoteRevisionRecorder $revisions,
        private readonly NegotiationService $negotiations,
    ) {}

    /**
     * Business drafts a quote against an accepted enquiry.
     *
     * @param  array<string, mixed>  $data  validated request payload
     */
    public function createDraft(Enquiry $enquiry, array $data): Quote
    {
        if (! $enquiry->allowsQuoting()) {
            throw ValidationException::withMessages([
                'enquiry' => ['Accept the enquiry before creating a quote.'],
            ]);
        }

        $calculated = $this->calculator->calculate($data['items'], $data);

        return DB::transaction(function () use ($enquiry, $data, $calculated): Quote {
            $quote = $this->numbers->withNumber(function (string $quoteNumber) use ($enquiry, $data, $calculated): Quote {
                $quote = new Quote([
                    'enquiry_id' => $enquiry->id,
                    // Never taken from the request: the parties are whoever the
                    // enquiry says they are.
                    'requirement_id' => $enquiry->requirement_id,
                    'customer_id' => $enquiry->customer_id,
                    'business_id' => $enquiry->business_id,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'currency' => $this->resolveCurrency($data['currency'] ?? null),
                    'validity_date' => $data['validity_date'] ?? null,
                    'estimated_delivery_date' => $data['estimated_delivery_date'] ?? null,
                    'estimated_completion_date' => $data['estimated_completion_date'] ?? null,
                    'payment_terms' => $data['payment_terms'] ?? null,
                    'warranty_terms' => $data['warranty_terms'] ?? null,
                    'cancellation_terms' => $data['cancellation_terms'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                $quote->quote_number = $quoteNumber;
                $quote->status = QuoteStatus::Draft;
                $this->applyTotals($quote, $calculated['totals']);
                $quote->save();

                return $quote;
            });

            $this->replaceItems($quote, $data['items'], $calculated['items']);

            return $quote->fresh(self::RELATIONS);
        });
    }

    /**
     * Edit a draft. Anything not present in the payload is left alone, except
     * items: if `items` is supplied it replaces the whole set, because the
     * totals have to be recomputed from a complete picture.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(Quote $quote, array $data): Quote
    {
        $this->assertEditable($quote);

        return DB::transaction(function () use ($quote, $data): Quote {
            foreach (['title', 'description', 'validity_date', 'estimated_delivery_date', 'estimated_completion_date', 'payment_terms', 'warranty_terms', 'cancellation_terms', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $quote->{$field} = $data[$field];
                }
            }

            if (array_key_exists('currency', $data) && $data['currency'] !== null) {
                $quote->currency = $this->resolveCurrency($data['currency']);
            }

            // Recalculate from the incoming items when supplied, otherwise from
            // the stored ones — quote-level charges can change on their own.
            $items = array_key_exists('items', $data)
                ? $data['items']
                : $quote->items->map(fn ($item) => [
                    'description' => $item->description,
                    'item_type' => $item->item_type->value,
                    'reference_id' => $item->reference_id,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                    'discount_amount' => $item->discount_amount,
                ])->all();

            $charges = [
                'delivery_fee' => $data['delivery_fee'] ?? $quote->delivery_fee,
                'other_charges' => $data['other_charges'] ?? $quote->other_charges,
                // The stored discount_amount includes line discounts, so it
                // cannot be reused as the quote-level input; an omitted
                // quote-level discount means "no additional discount".
                'discount_amount' => $data['discount_amount'] ?? 0,
            ];

            $calculated = $this->calculator->calculate($items, $charges);

            $this->applyTotals($quote, $calculated['totals']);
            $quote->save();

            $this->replaceItems($quote, $items, $calculated['items']);

            return $quote->fresh(self::RELATIONS);
        });
    }

    /** A draft can be thrown away; anything sent is a record and must be kept. */
    public function deleteDraft(Quote $quote): void
    {
        $this->assertEditable($quote);

        DB::transaction(function () use ($quote): void {
            $quote->items()->delete();
            $quote->delete();
        });
    }

    /**
     * Send the quote to the customer. This is the point the pricing freezes.
     *
     * expires_at is derived from validity_date (end of that day) so expiry is a
     * single comparable timestamp; a quote with no validity date simply never
     * expires.
     */
    public function send(Quote $quote): Quote
    {
        if (! $quote->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Only a draft quote can be sent.'],
            ]);
        }

        if ($quote->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => ['Add at least one line item before sending this quote.'],
            ]);
        }

        if ($quote->validity_date !== null && $quote->validity_date->endOfDay()->isPast()) {
            throw ValidationException::withMessages([
                'validity_date' => ['The validity date has already passed. Update it before sending.'],
            ]);
        }

        $quote->status = QuoteStatus::Sent;
        $quote->sent_at = now();
        $quote->expires_at = $quote->validity_date?->copy()->endOfDay();
        $quote->save();

        // Phase 6: sending is the moment the first commercial offer becomes real,
        // so it is recorded as revision 1 of the immutable ledger. Every later
        // counter appends to the same history, and an order is always traceable
        // to the exact revision it was formed from.
        $this->recordInitialRevision($quote);

        QuoteSent::dispatch($quote);

        return $quote->fresh(self::RELATIONS);
    }

    /**
     * Write revision 1 from the quote as sent. Idempotent: a quote that somehow
     * already has history is left alone rather than gaining a duplicate opening
     * offer.
     */
    private function recordInitialRevision(Quote $quote): void
    {
        if ($quote->revision_count > 0) {
            return;
        }

        $quote->loadMissing('items');
        $itemInputs = $quote->items->map(fn ($item) => [
            'description' => $item->description,
            'item_type' => $item->item_type->value,
            'reference_type' => $item->reference_type,
            'reference_id' => $item->reference_id,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => $item->unit_price,
            'tax_rate' => $item->tax_rate,
            'discount_amount' => $item->discount_amount,
        ])->all();

        // Recalculated rather than copied, so the ledger is provably consistent
        // with the calculator and never inherits a stale figure.
        $calculated = $this->calculator->calculate($itemInputs, [
            'delivery_fee' => $quote->delivery_fee,
            'other_charges' => $quote->other_charges,
            // The stored discount already includes line discounts; the quote-level
            // portion is whatever is left over once those are removed.
            'discount_amount' => max(0, (float) $quote->discount_amount - (float) $quote->items->sum(fn ($item) => (float) $item->discount_amount)),
        ]);

        $this->revisions->record(
            quote: $quote,
            calculated: $calculated,
            itemInputs: $itemInputs,
            authoredBy: NegotiationParty::Business,
            author: $quote->business?->user,
            revisionType: 'initial',
            terms: [
                'currency' => $quote->currency,
                'validity_date' => $quote->validity_date?->toDateString(),
                'estimated_delivery_date' => $quote->estimated_delivery_date?->toDateString(),
                'estimated_completion_date' => $quote->estimated_completion_date?->toDateString(),
                'payment_terms' => $quote->payment_terms,
                'warranty_terms' => $quote->warranty_terms,
                'cancellation_terms' => $quote->cancellation_terms,
                'notes' => $quote->notes,
            ],
        );
    }

    /**
     * Record the customer's first view. Also the natural moment to settle a
     * quote whose validity lapsed while it sat unread.
     */
    public function markViewedByCustomer(Quote $quote): Quote
    {
        if ($this->expireIfLapsed($quote)) {
            return $quote->fresh(self::RELATIONS);
        }

        if ($quote->status === QuoteStatus::Sent) {
            $quote->status = QuoteStatus::Viewed;
            $quote->viewed_at ??= now();
            $quote->save();

            QuoteViewed::dispatch($quote);
        }

        return $quote;
    }

    /**
     * Customer accepts.
     *
     * The row is re-read under a lock inside the transaction so two concurrent
     * accepts (double-click, retried request, two browser tabs) cannot both
     * pass the status check — the second sees the already-accepted state and is
     * rejected with a 422 rather than producing a second acceptance.
     */
    public function accept(Quote $quote, ?User $actor = null): Quote
    {
        // Phase 6: once a negotiation exists it owns the turn discipline, so the
        // customer's acceptance is delegated to it. That keeps quote and
        // negotiation settling together and makes accepting your own counter
        // offer impossible from either entry point.
        $negotiation = $quote->negotiation()->first();

        if ($negotiation !== null) {
            $this->negotiations->accept($quote, $actor ?? $quote->customer, NegotiationParty::Customer);

            return $quote->fresh(self::RELATIONS);
        }

        // Expiry is settled *before* the transaction opens, deliberately. Doing
        // it inside would mean the rollback triggered by the rejection below
        // also undid the relabelling, leaving a lapsed quote sitting in "sent"
        // forever.
        if ($this->expireIfLapsed($quote)) {
            throw ValidationException::withMessages([
                'status' => ['This quote has expired and can no longer be accepted.'],
            ]);
        }

        $accepted = DB::transaction(function () use ($quote): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === QuoteStatus::Accepted) {
                throw ValidationException::withMessages([
                    'status' => ['This quote has already been accepted.'],
                ]);
            }

            // Re-checked under the lock, in case the window closed in between.
            // No write here — the relabel above already handled that case.
            if ($locked->hasExpired()) {
                throw ValidationException::withMessages([
                    'status' => ['This quote has expired and can no longer be accepted.'],
                ]);
            }

            if (! $locked->isDecidableByCustomer()) {
                throw ValidationException::withMessages([
                    'status' => [sprintf('A %s quote cannot be accepted.', strtolower($locked->status->label()))],
                ]);
            }

            $locked->status = QuoteStatus::Accepted;
            $locked->accepted_at = now();
            $locked->viewed_at ??= now();
            $locked->save();

            return $locked;
        });

        // Fired after commit so a Phase 7 order listener only ever sees a
        // durably accepted quote.
        QuoteAccepted::dispatch($accepted);

        return $accepted->fresh(self::RELATIONS);
    }

    /** Customer rejects, optionally saying why. The quote is retained as history. */
    public function reject(Quote $quote, ?string $reason = null, ?User $actor = null): Quote
    {
        // Same delegation as accept: the negotiation, once open, is the authority
        // on whose move it is.
        $negotiation = $quote->negotiation()->first();

        if ($negotiation !== null) {
            $this->negotiations->reject($quote, $actor ?? $quote->customer, NegotiationParty::Customer, $reason);

            return $quote->fresh(self::RELATIONS);
        }

        $rejected = DB::transaction(function () use ($quote, $reason): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === QuoteStatus::Accepted) {
                throw ValidationException::withMessages([
                    'status' => ['This quote has already been accepted and can no longer be rejected.'],
                ]);
            }

            // An expired quote may still be formally rejected — it just cannot
            // be accepted — so lapsing is not settled here at all: rejecting
            // supersedes it, and relabelling first would fire a pointless
            // QuoteExpired event for a quote that is about to be rejected.
            if (! in_array($locked->status, [QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Expired], true)) {
                throw ValidationException::withMessages([
                    'status' => [sprintf('A %s quote cannot be rejected.', strtolower($locked->status->label()))],
                ]);
            }

            $locked->status = QuoteStatus::Rejected;
            $locked->rejected_at = now();
            $locked->rejection_reason = $reason;
            $locked->save();

            return $locked;
        });

        QuoteRejected::dispatch($rejected);

        return $rejected->fresh(self::RELATIONS);
    }

    /** Business pulls a live quote back before the customer has decided. */
    public function withdraw(Quote $quote): Quote
    {
        if ($quote->status === QuoteStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['This quote has not been sent yet — delete the draft instead.'],
            ]);
        }

        if (! $quote->isWithdrawable()) {
            throw ValidationException::withMessages([
                'status' => [sprintf('A %s quote cannot be withdrawn.', strtolower($quote->status->label()))],
            ]);
        }

        $quote->status = QuoteStatus::Withdrawn;
        $quote->withdrawn_at = now();
        $quote->save();

        QuoteWithdrawn::dispatch($quote);

        return $quote->fresh(self::RELATIONS);
    }

    /**
     * Relabel a lapsed quote. Returns true when the quote is (now) expired.
     *
     * Called on read and before every decision, which is what makes expiry
     * correct without a scheduler; the quotes:expire command exists only to
     * keep dashboards tidy.
     */
    public function expireIfLapsed(Quote $quote): bool
    {
        if ($quote->status === QuoteStatus::Expired) {
            return true;
        }

        if (! $quote->hasExpired()) {
            return false;
        }

        $quote->status = QuoteStatus::Expired;
        $quote->save();

        QuoteExpired::dispatch($quote);

        return true;
    }

    /** Bulk sweep for the scheduled command. Returns how many were relabelled. */
    public function expireLapsedQuotes(): int
    {
        $expired = 0;

        Quote::query()->lapsed()->cursor()->each(function (Quote $quote) use (&$expired): void {
            if ($this->expireIfLapsed($quote)) {
                $expired++;
            }
        });

        return $expired;
    }

    /**
     * Persist the calculated line items, replacing whatever was there.
     *
     * The client's own subtotal/tax/total values never reach the database: each
     * row is built from the calculator's output plus the descriptive fields.
     *
     * @param  array<int, array<string, mixed>>  $inputs
     * @param  array<int, array<string, string>>  $calculated
     */
    private function replaceItems(Quote $quote, array $inputs, array $calculated): void
    {
        $quote->items()->delete();

        foreach ($inputs as $index => $input) {
            $figures = $calculated[$index];
            $type = QuoteItemType::from($input['item_type'] ?? QuoteItemType::Custom->value);

            $item = $quote->items()->make([
                'sort_order' => $input['sort_order'] ?? $index,
                'description' => $input['description'],
                'item_type' => $type,
                ...$this->resolveReference($quote, $type, $input['reference_id'] ?? null),
                'quantity' => $figures['quantity'],
                'unit' => $input['unit'] ?? null,
                'unit_price' => $figures['unit_price'],
                'tax_rate' => $figures['tax_rate'],
                'discount_amount' => $figures['discount_amount'],
            ]);

            // Derived columns are deliberately not mass-assignable, so they are
            // force-filled from the calculator's output only.
            $item->forceFill([
                'subtotal' => $figures['subtotal'],
                'tax_amount' => $figures['tax_amount'],
                'total' => $figures['total'],
            ])->save();
        }
    }

    /**
     * Optionally link a line to the business's own catalogue entry.
     *
     * The reference is only kept when the row genuinely belongs to the quoting
     * business — otherwise a crafted reference_id could point a line at another
     * business's product. An invalid reference is dropped, not fatal, because
     * it is decorative metadata rather than part of the offer.
     *
     * @return array{reference_type: string|null, reference_id: int|null}
     */
    private function resolveReference(Quote $quote, QuoteItemType $type, mixed $referenceId): array
    {
        $empty = ['reference_type' => null, 'reference_id' => null];

        if ($referenceId === null || $type->referenceModel() === null) {
            return $empty;
        }

        $exists = match ($type) {
            QuoteItemType::Product => Product::query()->whereKey($referenceId)->where('business_id', $quote->business_id)->exists(),
            QuoteItemType::Service => BusinessService::query()->whereKey($referenceId)->where('business_id', $quote->business_id)->exists(),
            default => false,
        };

        return $exists
            ? ['reference_type' => $type->value, 'reference_id' => (int) $referenceId]
            : $empty;
    }

    /** @param  array<string, string>  $totals */
    private function applyTotals(Quote $quote, array $totals): void
    {
        $quote->forceFill([
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax_amount'],
            'delivery_fee' => $totals['delivery_fee'],
            'discount_amount' => $totals['discount_amount'],
            'other_charges' => $totals['other_charges'],
            'total_amount' => $totals['total_amount'],
        ]);
    }

    private function assertEditable(Quote $quote): void
    {
        if (! $quote->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Only a draft quote can be modified. Withdraw it and issue a new quote instead.'],
            ]);
        }
    }

    /**
     * Currency comes from the request when it is one the marketplace supports,
     * otherwise from configuration — never a hardcoded literal, so adding a
     * currency is a config change.
     */
    private function resolveCurrency(?string $currency): string
    {
        $supported = (array) config('marketplace.supported_currencies', []);
        $default = (string) config('marketplace.default_currency', 'INR');

        if ($currency === null) {
            return $default;
        }

        $normalised = strtoupper($currency);

        return in_array($normalised, $supported, true) ? $normalised : $default;
    }
}
