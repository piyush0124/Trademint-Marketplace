<?php

namespace App\Services\Quotes;

use App\Enums\NegotiationParty;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes immutable quote revisions.
 *
 * Every commercial offer in the system passes through here: the opening offer
 * when a quote is sent, and each counter offer thereafter. Centralising it means
 * a revision can never be written with money the calculator did not produce, and
 * the "one current revision per quote" invariant is maintained in one place.
 *
 * The recorder also keeps the parent `quotes` row in step with the current
 * revision. That is deliberate duplication: it keeps every Phase 5 endpoint,
 * resource and screen working unchanged while the revision table becomes the
 * authoritative history.
 */
class QuoteRevisionRecorder
{
    /**
     * Append a revision and make it current.
     *
     * @param  array{totals: array<string, string>, items: array<int, array<string, string>>}  $calculated  QuoteCalculator output
     * @param  array<int, array<string, mixed>>  $itemInputs  the descriptive side of each line
     * @param  array<string, mixed>  $terms  dates, terms, notes, message, is_final_offer
     */
    public function record(
        Quote $quote,
        array $calculated,
        array $itemInputs,
        NegotiationParty $authoredBy,
        ?User $author,
        string $revisionType,
        array $terms = [],
    ): QuoteRevision {
        return DB::transaction(function () use ($quote, $calculated, $itemInputs, $authoredBy, $author, $revisionType, $terms): QuoteRevision {
            // Lock the quote so two concurrent offers cannot both read the same
            // revision_count and collide on the unique (quote_id, number) index.
            /** @var Quote $locked */
            $locked = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $nextNumber = ((int) $locked->revision_count) + 1;

            // Retire the previous current revision. `is_current`/`superseded_at`
            // are the only columns a written revision permits changing.
            QuoteRevision::query()
                ->where('quote_id', $locked->id)
                ->where('is_current', true)
                ->get()
                ->each(function (QuoteRevision $previous): void {
                    $previous->is_current = false;
                    $previous->superseded_at = now();
                    $previous->save();
                });

            $validityDate = $terms['validity_date'] ?? null;

            $revision = QuoteRevision::create([
                'quote_id' => $locked->id,
                'revision_number' => $nextNumber,
                'authored_by' => $authoredBy,
                'authored_by_user_id' => $author?->id,
                'revision_type' => $revisionType,
                'is_final_offer' => (bool) ($terms['is_final_offer'] ?? false),

                'subtotal' => $calculated['totals']['subtotal'],
                'tax_amount' => $calculated['totals']['tax_amount'],
                'delivery_fee' => $calculated['totals']['delivery_fee'],
                'discount_amount' => $calculated['totals']['discount_amount'],
                'other_charges' => $calculated['totals']['other_charges'],
                'total_amount' => $calculated['totals']['total_amount'],
                'currency' => $terms['currency'] ?? $locked->currency,

                'validity_date' => $validityDate,
                'estimated_delivery_date' => $terms['estimated_delivery_date'] ?? null,
                'estimated_completion_date' => $terms['estimated_completion_date'] ?? null,
                // A single comparable timestamp derived from the offer's own
                // validity date, so expiry is one comparison everywhere.
                'expires_at' => $validityDate !== null ? Carbon::parse($validityDate)->endOfDay() : null,

                'payment_terms' => $terms['payment_terms'] ?? null,
                'warranty_terms' => $terms['warranty_terms'] ?? null,
                'cancellation_terms' => $terms['cancellation_terms'] ?? null,
                'notes' => $terms['notes'] ?? null,
                'message' => $terms['message'] ?? null,

                'is_current' => true,
            ]);

            foreach ($itemInputs as $index => $input) {
                $figures = $calculated['items'][$index];

                $revision->items()->create([
                    'sort_order' => $input['sort_order'] ?? $index,
                    'description' => $input['description'],
                    'item_type' => $input['item_type'] ?? 'custom',
                    'reference_type' => $input['reference_type'] ?? null,
                    'reference_id' => $input['reference_id'] ?? null,
                    'quantity' => $figures['quantity'],
                    'unit' => $input['unit'] ?? null,
                    'unit_price' => $figures['unit_price'],
                    'tax_rate' => $figures['tax_rate'],
                    'tax_amount' => $figures['tax_amount'],
                    'discount_amount' => $figures['discount_amount'],
                    'subtotal' => $figures['subtotal'],
                    'total' => $figures['total'],
                ]);
            }

            $locked->forceFill([
                'current_revision_id' => $revision->id,
                'revision_count' => $nextNumber,
            ])->save();

            return $revision->load('items');
        });
    }

    /**
     * Mirror a revision onto the parent quote, so the quote always shows the
     * offer currently on the table. Money columns are force-filled because they
     * are intentionally not mass-assignable.
     */
    public function syncQuoteToRevision(Quote $quote, QuoteRevision $revision): Quote
    {
        $quote->forceFill([
            'subtotal' => $revision->subtotal,
            'tax_amount' => $revision->tax_amount,
            'delivery_fee' => $revision->delivery_fee,
            'discount_amount' => $revision->discount_amount,
            'other_charges' => $revision->other_charges,
            'total_amount' => $revision->total_amount,
            'currency' => $revision->currency,
            'validity_date' => $revision->validity_date,
            'estimated_delivery_date' => $revision->estimated_delivery_date,
            'estimated_completion_date' => $revision->estimated_completion_date,
            'expires_at' => $revision->expires_at,
            'payment_terms' => $revision->payment_terms,
            'warranty_terms' => $revision->warranty_terms,
            'cancellation_terms' => $revision->cancellation_terms,
            'notes' => $revision->notes,
        ])->save();

        // The quote's own line items track the current offer too, so the
        // existing quote screens and PDF-style preview need no special casing.
        $quote->items()->delete();

        foreach ($revision->items as $item) {
            $quote->items()->create([
                'sort_order' => $item->sort_order,
                'description' => $item->description,
                'item_type' => $item->item_type,
                'reference_type' => $item->reference_type,
                'reference_id' => $item->reference_id,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate,
                'discount_amount' => $item->discount_amount,
            ])->forceFill([
                'tax_amount' => $item->tax_amount,
                'subtotal' => $item->subtotal,
                'total' => $item->total,
            ])->save();
        }

        return $quote->fresh(['items']);
    }
}
