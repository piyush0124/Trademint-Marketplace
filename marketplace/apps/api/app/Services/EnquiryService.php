<?php

namespace App\Services;

use App\Enums\EnquiryStatus;
use App\Enums\MatchResponseStatus;
use App\Events\EnquiryAccepted;
use App\Events\EnquiryDeclined;
use App\Models\Business;
use App\Models\Enquiry;
use App\Models\Requirement;
use App\Models\RequirementMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 — enquiry workflow.
 *
 * An enquiry is the commercial conversation that follows a match. It exists so
 * that quoting has a single, authorised gate: a business may only quote a
 * requirement it has an *accepted* enquiry for.
 *
 * Relationship to Phase 4's RequirementMatch: the match answers "is this
 * business suitable", the enquiry answers "are these two actually engaging".
 * To avoid two competing sources of truth, the two are kept in step here —
 * accepting or declining an enquiry also settles the underlying match, and
 * MatchingService's own accept/decline routes back through this service. The
 * match remains the immutable record of *why* they were paired.
 *
 * Follows the existing service conventions: explicit method per transition,
 * pre-conditions checked rather than assumed, ValidationException for rule
 * violations (rendered 422), transactions around multi-row writes.
 */
class EnquiryService
{
    public const RELATIONS = ['requirement.category', 'requirement.location', 'business.location', 'match'];

    /**
     * Customer opens an enquiry with a business that was matched to their
     * requirement.
     *
     * Idempotent: a second attempt returns the existing conversation instead
     * of creating a duplicate (the unique index on requirement+business makes
     * that a hard guarantee, this makes it a friendly one).
     */
    public function openFromCustomer(Requirement $requirement, RequirementMatch $match, ?string $message = null): Enquiry
    {
        $this->assertRequirementOpen($requirement);

        if ($match->requirement_id !== $requirement->id) {
            throw ValidationException::withMessages([
                'match_id' => ['That match does not belong to this requirement.'],
            ]);
        }

        return DB::transaction(function () use ($requirement, $match, $message): Enquiry {
            $enquiry = Enquiry::query()
                ->where('requirement_id', $requirement->id)
                ->where('business_id', $match->business_id)
                ->lockForUpdate()
                ->first();

            if ($enquiry !== null) {
                // Re-opening a cancelled conversation is reasonable; overwriting
                // a live one's message is not, so only the message is refreshed
                // when the customer is genuinely restarting it.
                if ($enquiry->status === EnquiryStatus::Cancelled) {
                    $enquiry->status = EnquiryStatus::Pending;
                    $enquiry->cancelled_at = null;
                    $enquiry->customer_message = $message ?? $enquiry->customer_message;
                    $enquiry->save();
                }

                return $enquiry->fresh(self::RELATIONS);
            }

            $enquiry = Enquiry::create([
                'requirement_id' => $requirement->id,
                // Ownership is derived from the requirement and the match, never
                // from client input — see EnquiryController.
                'customer_id' => $requirement->customer_id,
                'business_id' => $match->business_id,
                'match_id' => $match->id,
                'status' => EnquiryStatus::Pending,
                'initiated_by' => 'customer',
                'customer_message' => $message,
            ]);

            return $enquiry->load(self::RELATIONS);
        });
    }

    /**
     * Ensure an enquiry exists for a match, used when the *business* moves
     * first by responding to its invitation. Does not change an existing
     * conversation's status.
     */
    public function ensureForMatch(RequirementMatch $match, string $initiatedBy = 'business'): Enquiry
    {
        $match->loadMissing('requirement');

        return DB::transaction(function () use ($match, $initiatedBy): Enquiry {
            $existing = Enquiry::query()
                ->where('requirement_id', $match->requirement_id)
                ->where('business_id', $match->business_id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->match_id === null) {
                    $existing->match_id = $match->id;
                    $existing->save();
                }

                return $existing;
            }

            return Enquiry::create([
                'requirement_id' => $match->requirement_id,
                'customer_id' => $match->requirement->customer_id,
                'business_id' => $match->business_id,
                'match_id' => $match->id,
                'status' => EnquiryStatus::Pending,
                'initiated_by' => $initiatedBy,
            ]);
        });
    }

    /**
     * Business accepts: it is willing to engage, and may now quote. Accepting
     * does not commit to any price — that is what the quote is for.
     */
    public function accept(Enquiry $enquiry, ?string $message = null): Enquiry
    {
        $this->assertBusinessCanRespond($enquiry);

        DB::transaction(function () use ($enquiry, $message): void {
            $enquiry->status = EnquiryStatus::Accepted;
            $enquiry->accepted_at = now();
            $enquiry->declined_at = null;
            if ($message !== null) {
                $enquiry->business_message = $message;
            }
            $enquiry->save();

            $this->syncMatchResponse($enquiry, MatchResponseStatus::Accepted, $message);
        });

        EnquiryAccepted::dispatch($enquiry);

        return $enquiry->fresh(self::RELATIONS);
    }

    /** Business declines. The conversation is closed but kept for history. */
    public function decline(Enquiry $enquiry, ?string $message = null): Enquiry
    {
        $this->assertBusinessCanRespond($enquiry);

        DB::transaction(function () use ($enquiry, $message): void {
            $enquiry->status = EnquiryStatus::Declined;
            $enquiry->declined_at = now();
            if ($message !== null) {
                $enquiry->business_message = $message;
            }
            $enquiry->save();

            $this->syncMatchResponse($enquiry, MatchResponseStatus::Declined, $message);
        });

        EnquiryDeclined::dispatch($enquiry);

        return $enquiry->fresh(self::RELATIONS);
    }

    /** Customer withdraws an enquiry the business has not answered yet. */
    public function cancel(Enquiry $enquiry): Enquiry
    {
        if ($enquiry->status->isFinal() || $enquiry->status === EnquiryStatus::Accepted) {
            throw ValidationException::withMessages([
                'status' => ['This enquiry can no longer be cancelled.'],
            ]);
        }

        $enquiry->status = EnquiryStatus::Cancelled;
        $enquiry->cancelled_at = now();
        $enquiry->save();

        return $enquiry->fresh(self::RELATIONS);
    }

    /**
     * Conclude an accepted enquiry — either side may do so once the exchange
     * has run its course. Kept generic: closing carries no commercial meaning,
     * and specifically is not an outcome ("won"/"lost" belongs to later phases).
     */
    public function close(Enquiry $enquiry): Enquiry
    {
        if ($enquiry->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => ['This enquiry is already closed.'],
            ]);
        }

        $enquiry->status = EnquiryStatus::Closed;
        $enquiry->closed_at = now();
        $enquiry->save();

        return $enquiry->fresh(self::RELATIONS);
    }

    /** Records the customer's follow-up message without changing the status. */
    public function addCustomerMessage(Enquiry $enquiry, string $message): Enquiry
    {
        if ($enquiry->status->isFinal()) {
            throw ValidationException::withMessages([
                'message' => ['This enquiry is no longer open.'],
            ]);
        }

        $enquiry->customer_message = $message;
        $enquiry->save();

        return $enquiry->fresh(self::RELATIONS);
    }

    /**
     * Keeps the Phase 4 match in step with the Phase 5 decision, without
     * overwriting a response the business already gave through the match
     * endpoints — first answer wins either way.
     */
    private function syncMatchResponse(Enquiry $enquiry, MatchResponseStatus $status, ?string $note): void
    {
        $match = $enquiry->match_id !== null
            ? RequirementMatch::query()->whereKey($enquiry->match_id)->lockForUpdate()->first()
            : null;

        if ($match === null || $match->hasResponded()) {
            return;
        }

        $match->response_status = $status;
        $match->responded_at = now();
        $match->response_note = $note;
        if ($match->viewed_at === null) {
            $match->viewed_at = now();
        }
        $match->save();
    }

    private function assertBusinessCanRespond(Enquiry $enquiry): void
    {
        if (! $enquiry->isPending()) {
            throw ValidationException::withMessages([
                'status' => [sprintf('This enquiry has already been %s.', strtolower($enquiry->status->label()))],
            ]);
        }

        $this->assertRequirementOpen($enquiry->requirement);
    }

    /**
     * A requirement that has been closed or cancelled is not open for new
     * commercial activity. Reuses the same "publicly visible" definition the
     * marketplace and matching engine already use, rather than inventing a
     * second notion of "open".
     */
    private function assertRequirementOpen(?Requirement $requirement): void
    {
        if ($requirement === null || ! $requirement->isMatchable()) {
            throw ValidationException::withMessages([
                'requirement' => ['This requirement is no longer open for enquiries.'],
            ]);
        }
    }

    /** The business record for the authenticated owner, or a 422 if they have none yet. */
    public function requireBusiness(?Business $business): Business
    {
        if ($business === null) {
            throw ValidationException::withMessages([
                'business' => ['Create your business profile before using enquiries.'],
            ]);
        }

        return $business;
    }
}
