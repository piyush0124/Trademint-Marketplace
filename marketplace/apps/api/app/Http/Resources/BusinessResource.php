<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-safe by default: gst_number and raw verification timestamps are
 * only included when the viewer is the business's own owner or an admin.
 * Everyone else — including anonymous visitors — sees verification as
 * simple booleans ("is this verified, yes/no"), never the sensitive
 * underlying detail.
 */
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->id === $this->user_id;
        $isAdmin = $viewer !== null && $viewer->isAdmin();
        $canSeePrivate = $isOwner || $isAdmin;

        return [
            'id' => $this->id,
            'type' => 'business',
            'attributes' => [
                'slug' => $this->slug,
                'name' => $this->name,
                'business_type' => $this->business_type,
                'years_in_business' => $this->years_in_business,
                'trade_type' => $this->trade_type?->value,
                'logo_url' => $this->logoUrl(),
                'cover_url' => $this->coverUrl(),
                'about' => $this->about,
                'phone' => $this->phone,
                'whatsapp' => $this->whatsapp,
                'email' => $this->email,
                'website' => $this->website,
                'business_hours' => $this->business_hours,
                'is_available' => $this->is_available,
                'is_verified' => $this->is_verified,
                // Null rating means "not rated yet" — the frontend must show
                // that as "No ratings yet", never as a zero score.
                'rating' => $this->rating,
                'review_count' => (int) $this->review_count,
                'is_fully_verified' => $this->isFullyVerified(),
                // Phase 9 platform verification state. Public on purpose — a buyer
                // is entitled to know whether a seller has been vetted, and it is
                // already published on the passport. The evidence behind it (the
                // documents) remains private.
                'verification_status' => $this->verificationStatus()->value,
                'verification_status_label' => $this->verificationStatus()->label(),
                'is_suspended' => $this->isSuspended(),
                // Operational counts for the admin queue, when they were selected.
                'orders_count' => $this->when(isset($this->orders_count), $this->orders_count),
                'reviews_count' => $this->when(isset($this->reviews_count), $this->reviews_count),
                'documents_count' => $this->when(isset($this->documents_count), $this->documents_count),
                'verification' => [
                    'email' => $this->email_verified_at !== null,
                    'phone' => $this->phone_verified_at !== null,
                    'business' => $this->business_verified_at !== null,
                    'address' => $this->address_verified_at !== null,
                    'gst' => $this->gst_verified_at !== null,
                    /*
                     * Whether a GST number is on file at all — not the number itself.
                     *
                     * Needed because the public verification checklist has to
                     * distinguish "GST not applicable" from "GST not yet confirmed",
                     * and `gst === false` alone cannot tell them apart. Without this
                     * flag the buyer-facing checklist either hides a confirmed GST
                     * check or reports a supplier below the registration threshold as
                     * having an outstanding one.
                     *
                     * Safe to publish: it reveals only that the business is
                     * GST-registered, which is already publicly searchable in India,
                     * and carries no identifier. The number stays owner/admin-only
                     * below.
                     */
                    'gst_applicable' => $this->gst_number !== null,
                ],
                // GST number itself is sensitive government ID data —
                // never shown to the public, only to the owner/admin.
                'gst_number' => $this->when($canSeePrivate, $this->gst_number),
                'listings_count' => $this->when(isset($this->listings_count), $this->listings_count),
            ],
            'relationships' => [
                'location' => new LocationResource($this->whenLoaded('location')),
                'category' => new CategoryResource($this->whenLoaded('category')),
                'industries' => IndustryResource::collection($this->whenLoaded('industries')),
                'service_areas' => LocationResource::collection($this->whenLoaded('serviceAreas')),
            ],
        ];
    }
}
