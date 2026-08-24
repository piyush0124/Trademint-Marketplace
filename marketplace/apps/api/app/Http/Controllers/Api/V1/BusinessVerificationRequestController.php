<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessDocument;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 9 — the business's own side of verification: upload documents, request
 * review, see status. Granting verification is an admin action and lives on the
 * admin controller, because a business must never be able to verify itself.
 */
class BusinessVerificationRequestController extends Controller
{
    public function __construct(private readonly VerificationService $verification) {}

    /** The authenticated owner's verification state and document list. */
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        abort_if($business === null, 404, 'Create your business profile first.');

        return response()->json([
            'success' => true,
            'message' => 'Verification status retrieved successfully.',
            'data' => [
                'status' => $business->verificationStatus()->value,
                'status_label' => $business->verificationStatus()->label(),
                'notes' => $business->verification_notes,
                'requested_at' => $business->verification_requested_at?->toIso8601String(),
                'verified_at' => $business->verified_at?->toIso8601String(),
                'suspension_reason' => $business->suspension_reason,
                'can_request' => $business->verificationStatus()->allowsRequest(),
                // Published so the upload form is driven by the same list the
                // validator uses, rather than a copy that can drift out of step.
                'document_types' => BusinessDocument::TYPES,
                'accepted_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
                'max_file_size_kb' => 5120,
                'documents' => $business->documents()->latest()->get()->map(fn (BusinessDocument $document) => [
                    'id' => $document->uuid,
                    'type' => $document->type,
                    'title' => $document->title,
                    'status' => $document->status,
                    'review_notes' => $document->review_notes,
                    'uploaded_at' => $document->created_at?->toIso8601String(),
                    'reviewed_at' => $document->reviewed_at?->toIso8601String(),
                    // Deliberately no URL: downloads go through an authorised route.
                ])->all(),
            ],
        ]);
    }

    /**
     * Upload a supporting document.
     *
     * Validation is strict on both extension and MIME type, and the stored filename
     * is generated — the client's filename never touches the path.
     */
    public function storeDocument(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        abort_if($business === null, 404, 'Create your business profile first.');
        abort_unless($request->user()->isOwner(), 403, 'You do not have permission to perform this action.');

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(BusinessDocument::TYPES)],
            'title' => ['nullable', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);

        $document = $this->verification->storeDocument(
            $business,
            $validated['type'],
            $request->file('file'),
            $validated['title'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded. An administrator will review it.',
            'data' => [
                'id' => $document->uuid,
                'type' => $document->type,
                'status' => $document->status,
                'uploaded_at' => $document->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** Ask an admin to review the uploaded documents. */
    public function request(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        abort_if($business === null, 404, 'Create your business profile first.');
        abort_unless($request->user()->isOwner(), 403, 'You do not have permission to perform this action.');

        $business = $this->verification->request($business);

        return response()->json([
            'success' => true,
            'message' => 'Verification requested. An administrator will review your documents.',
            'data' => [
                'status' => $business->verificationStatus()->value,
                'status_label' => $business->verificationStatus()->label(),
                'requested_at' => $business->verification_requested_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Download a document. Restricted to the owning business and admins, and served
     * from a private disk — these files have no public URL at any point.
     */
    public function downloadDocument(Request $request, BusinessDocument $document): StreamedResponse
    {
        $document->loadMissing('business');

        $user = $request->user();
        $isOwner = $document->business !== null && $document->business->user_id === $user->id;

        abort_unless($isOwner || $user->isAdmin(), 403, 'You do not have permission to perform this action.');

        $contents = $this->verification->documentContents($document);

        return response()->streamDownload(
            fn () => print ($contents),
            sprintf('%s-%s', $document->type, basename($document->file_path)),
            ['Content-Type' => $document->file_mime ?? 'application/octet-stream'],
        );
    }
}
