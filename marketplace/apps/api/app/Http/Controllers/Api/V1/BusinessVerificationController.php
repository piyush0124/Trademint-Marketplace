<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VerifyBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Verification can ONLY be granted here, by an admin, one dimension at a
 * time. There is no self-service or automatic path to a "verified" badge
 * anywhere else in the app — see Business::isFullyVerified().
 */
class BusinessVerificationController extends Controller
{
    private const COLUMN_MAP = [
        'email' => 'email_verified_at',
        'phone' => 'phone_verified_at',
        'business' => 'business_verified_at',
        'address' => 'address_verified_at',
        'gst' => 'gst_verified_at',
    ];

    public function verify(VerifyBusinessRequest $request, Business $business): JsonResponse
    {
        $type = $request->validated('type');

        if ($type === 'gst' && $business->gst_number === null) {
            throw ValidationException::withMessages([
                'type' => ['This business has no GST number on file to verify.'],
            ]);
        }

        $business->{self::COLUMN_MAP[$type]} = now();
        // The legacy single `is_verified` flag mirrors "every dimension
        // has actually been checked" — it is only ever set true here,
        // never anywhere else, and never on a partial/self-reported basis.
        $business->is_verified = $business->isFullyVerified();
        $business->save();

        return response()->json([
            'success' => true,
            'message' => ucfirst($type).' verified.',
            'data' => new BusinessResource($business->fresh(['location', 'category', 'industries', 'serviceAreas'])),
        ]);
    }
}
