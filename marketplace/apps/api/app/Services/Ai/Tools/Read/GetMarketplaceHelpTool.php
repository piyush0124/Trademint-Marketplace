<?php

namespace App\Services\Ai\Tools\Read;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\BaseTool;

/**
 * How the marketplace works.
 *
 * A tool rather than a chunk of system prompt, for two reasons: the prompt stays
 * short (and therefore cheap on every single turn), and the model has to *fetch* an
 * explanation rather than improvise one. When a buyer asks "how does negotiation
 * work", the answer describes the mechanism this codebase actually implements —
 * turn-based counter-offers with an immutable revision ledger — instead of a
 * plausible-sounding generic account of marketplace negotiation.
 *
 * The topics are written against the real Phase 5–10 behaviour and are the one place
 * in the AI layer where explanatory text is authored rather than retrieved.
 */
class GetMarketplaceHelpTool extends BaseTool
{
    /**
     * Topic keys are stable; the text is the source of truth for "how does X work"
     * answers.
     *
     * @var array<string, string>
     */
    private const TOPICS = [
        'getting_started' => 'Buyers post a requirement describing what they need. The matching engine scores every eligible supplier on category, capability, location, budget fit, availability and track record, then invites the strongest matches. Those suppliers send itemised quotes, which the buyer compares side by side. Accepting a quote creates an order automatically.',

        'requirements' => 'A requirement starts as a draft and can be edited freely. Publishing requires a title, quantity, location and required date, and triggers a matching run. Once published the requirement moves to matching, then to receiving quotes. A requirement can be cancelled at any point before it closes; it is never deleted, so the buyer keeps a full history.',

        'matching' => 'Matching is deterministic and rule-based, not AI. Six weighted dimensions are scored: category, capability, location, budget, availability and trust. Weights are configurable per deployment. A supplier scoring below the minimum threshold is never invited, because a weak match wastes both sides\' time. Each match stores its score and the reasons behind it, so the figure can always be explained.',

        'enquiries' => 'An enquiry is the conversation between buyer and supplier about a requirement, before any pricing. The supplier accepts or declines. Only after accepting can they build a quote, which keeps unpriced conversation and formal offers separate.',

        'quotes' => 'A quote is itemised: each line has a description, quantity, unit price, tax rate and optional discount. All arithmetic is performed by the backend, so a total can never be edited in a browser. A quote is a draft until sent, and a sent quote is frozen and never deleted. Quotes carry a validity date and expire automatically.',

        'negotiation' => 'Either side can counter a quote. Each counter creates a new immutable revision, and the previous one is kept — the full history is visible to both parties. Negotiation is turn-based: only the party who did not author the current revision may counter, accept or reject it, and only the author may withdraw it. Accepting a revision accepts those exact figures.',

        'orders' => 'Accepting a quote creates the order automatically, with the agreed lines copied from the accepted revision. The order then moves through a status flow the supplier drives while the buyer confirms completion. States can be skipped where they do not apply — a consultation never becomes "dispatched". Every status change is recorded in an append-only history.',

        'payments' => 'Payment amounts are always the balance the server calculated, never a figure sent by a client. The default flow is offline settlement: the buyer starts a payment, pays out of band, and the supplier confirms receipt. Refunds, full or partial, are issued by the supplier against a settled payment. The order\'s payment position is recomputed from its payments rather than incremented.',

        'cancellation' => 'A buyer can cancel an order only before the supplier has started work. After that it is a dispute rather than a cancellation, which protects a supplier who has already committed effort. A supplier can cancel while the order is pending or confirmed.',

        'reviews' => 'A review can only be written by the buyer on a completed order, once. That single rule makes review stuffing and reviewing a supplier you never traded with impossible. Reviews cannot be edited or deleted. The supplier may publish one public reply. An administrator can hide a review but never delete it, and a supplier disliking a review is not grounds to hide it.',

        'verification' => 'A supplier uploads registration documents and requests verification. A human administrator reviews them and approves, rejects with a reason, or suspends. Suppliers cannot verify themselves. Documents are stored privately and are readable only by their owner and administrators — they are never published, only their approval status is.',

        'trust_score' => 'The trust score is a weighted blend of verification, profile completeness, completed orders, review quality, responsiveness, reliability and account age. Factors with no evidence are excluded from the weighting rather than scored zero, so a new supplier reads as unproven rather than bad. Every factor publishes the evidence behind it.',

        'disputes' => 'Either party can raise a dispute on a live order, once at a time, choosing a reason and describing what happened. The description is immutable. Only an administrator decides the outcome — neither party can resolve their own dispute. Resolving a dispute does not move money; any refund is issued separately against the payment.',

        'fees' => 'Posting a requirement is free for buyers and there is no commission charged on quotes. Suppliers list their business free of charge.',
    ];

    public function name(): string
    {
        return 'get_marketplace_help';
    }

    public function description(): string
    {
        return 'Get the authoritative explanation of how a marketplace process works (quotes, negotiation, orders, '
            .'payments, reviews, disputes, verification, fees). Always use this instead of explaining a process from memory.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'topic' => $this->stringProperty('The process to explain.', array_keys(self::TOPICS)),
        ], ['topic']);
    }

    public function roles(): array
    {
        return [UserRole::Customer, UserRole::Owner, UserRole::Admin];
    }

    public function handle(User $user, array $arguments): array
    {
        $topic = (string) $arguments['topic'];

        return [
            'topic' => $topic,
            'explanation' => self::TOPICS[$topic] ?? 'No help is available for that topic.',
            'available_topics' => array_keys(self::TOPICS),
        ];
    }
}
