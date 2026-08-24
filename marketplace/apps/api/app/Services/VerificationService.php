<?php

namespace App\Services;

use App\Enums\VerificationStatus;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 9 — business verification.
 *
 * The asymmetry is the point: a business may *request* verification and upload
 * documents; only an admin may grant, reject or suspend. There is no code path by
 * which a business can set its own verification status, and the legacy `is_verified`
 * flag is kept in step here so nothing downstream can be fooled by editing one and
 * not the other.
 *
 * Documents go to a private disk. Nothing about them is public except type and
 * status.
 */
class VerificationService
{
    /** Documents are never written to a web-accessible disk. */
    private const DISK = 'local';

    /** Business asks to be verified. */
    public function request(Business $business): Business
    {
        $status = $business->verificationStatus();

        if (! $status->allowsRequest()) {
            throw ValidationException::withMessages([
                'verification' => [sprintf('Verification is already %s.', strtolower($status->label()))],
            ]);
        }

        if ($business->documents()->count() === 0) {
            throw ValidationException::withMessages([
                'documents' => ['Upload at least one supporting document before requesting verification.'],
            ]);
        }

        $business->forceFill([
            'verification_status' => VerificationStatus::Pending,
            'verification_requested_at' => now(),
            'verification_notes' => null,
        ])->save();

        return $business->fresh();
    }

    /** Admin grants verification. */
    public function approve(Business $business, User $admin, ?string $notes = null): Business
    {
        $business->forceFill([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
            'verified_by_user_id' => $admin->id,
            'verification_notes' => $notes,
            'suspended_at' => null,
            'suspension_reason' => null,
            // Keep the Phase 2 flag consistent so no consumer sees a contradiction.
            'is_verified' => true,
        ])->save();

        return $business->fresh();
    }

    public function reject(Business $business, User $admin, string $reason): Business
    {
        $business->forceFill([
            'verification_status' => VerificationStatus::Rejected,
            'verified_at' => null,
            'verified_by_user_id' => $admin->id,
            'verification_notes' => $reason,
            'is_verified' => false,
        ])->save();

        return $business->fresh();
    }

    /**
     * Admin suspends a business. It keeps access to its existing commercial threads
     * — suspending must not strand a customer mid-order — but stops being presented
     * as a viable new supplier (see the discovery filters and matching).
     */
    public function suspend(Business $business, User $admin, string $reason): Business
    {
        $business->forceFill([
            'verification_status' => VerificationStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
            'verified_by_user_id' => $admin->id,
            'is_verified' => false,
            // A suspended business is not available for new work.
            'is_available' => false,
        ])->save();

        return $business->fresh();
    }

    /** Admin lifts a suspension, returning the business to unverified. */
    public function reinstate(Business $business, User $admin, ?string $notes = null): Business
    {
        $business->forceFill([
            'verification_status' => VerificationStatus::Unverified,
            'suspended_at' => null,
            'suspension_reason' => null,
            'verification_notes' => $notes,
            'verified_by_user_id' => $admin->id,
            'is_available' => true,
        ])->save();

        return $business->fresh();
    }

    /** Business uploads a supporting document to the private disk. */
    public function storeDocument(Business $business, string $type, UploadedFile $file, ?string $title = null): BusinessDocument
    {
        // Stored under a per-business prefix with a generated name — the original
        // filename is never used as a path, which closes off traversal entirely.
        $path = $file->store(sprintf('business-documents/%d', $business->id), self::DISK);

        $document = $business->documents()->create([
            'type' => $type,
            'title' => $title,
            'file_path' => $path,
            'file_mime' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        $document->forceFill(['status' => BusinessDocument::STATUS_PENDING])->save();

        return $document->fresh();
    }

    /** Admin approves or rejects a single document. */
    public function reviewDocument(BusinessDocument $document, User $admin, string $status, ?string $notes = null): BusinessDocument
    {
        if (! in_array($status, [BusinessDocument::STATUS_APPROVED, BusinessDocument::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => ['A document can only be approved or rejected.'],
            ]);
        }

        $document->forceFill([
            'status' => $status,
            'review_notes' => $notes,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
        ])->save();

        return $document->fresh();
    }

    /** Streamed download, for the owning business or an admin only. */
    public function documentContents(BusinessDocument $document): string
    {
        if (! Storage::disk(self::DISK)->exists($document->file_path)) {
            throw ValidationException::withMessages([
                'document' => ['That file is no longer available.'],
            ]);
        }

        return Storage::disk(self::DISK)->get($document->file_path);
    }

    public function disk(): string
    {
        return self::DISK;
    }
}
