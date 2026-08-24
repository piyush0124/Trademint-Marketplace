<?php

namespace App\Services;

use App\Enums\AvailabilityStatus;
use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns all listing workflow business logic. Controllers stay thin and only
 * translate HTTP <-> these methods; nothing here trusts a status value sent
 * from the client — every transition is an explicit method with its own
 * pre-condition checks.
 */
class ListingService
{
    /**
     * Create a brand new listing in DRAFT. Never publishes automatically.
     */
    public function createDraft(User $user, array $data): Listing
    {
        return DB::transaction(function () use ($user, $data): Listing {
            $category = Category::where('slug', $data['category_slug'])->where('is_active', true)->firstOrFail();
            $location = $this->resolveLocation($data['location'] ?? null);

            $listing = Listing::create([
                'user_id' => $user->id,
                'business_id' => $user->business?->id,
                'category_id' => $category->id,
                'location_id' => $location?->id,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'price' => $data['price'] ?? null,
                'price_max' => $data['price_max'] ?? null,
                'unit' => $data['unit'] ?? null,
                'min_order_quantity' => $data['min_order_quantity'] ?? null,
                'currency' => $data['currency'] ?? config('marketplace.default_currency'),
                'status' => ListingStatus::Draft,
                'availability_status' => AvailabilityStatus::Available,
            ]);

            if (array_key_exists('attributes', $data)) {
                $this->syncAttributes($listing, $category, $data['attributes'] ?? [], validateRequired: false);
            }

            return $listing->load(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);
        });
    }

    /**
     * Update a listing while it is still owner-editable (DRAFT or REJECTED).
     * A rejected listing that is edited quietly returns to DRAFT so the
     * owner explicitly has to resubmit it.
     */
    public function updateDraft(Listing $listing, array $data): Listing
    {
        return DB::transaction(function () use ($listing, $data): Listing {
            if (isset($data['location'])) {
                $location = $this->resolveLocation($data['location']);
                $listing->location_id = $location?->id;
            }

            // Only touch a field if the client actually sent that key.
            // A present key — even an explicit null — must be applied
            // (e.g. clearing the description); a missing key must not
            // overwrite the existing value. array_filter() on ?? null
            // used to silently drop explicit nulls, so a cleared field
            // never actually got cleared.
            foreach (['title', 'description', 'price', 'price_max', 'unit', 'min_order_quantity', 'currency'] as $field) {
                if (array_key_exists($field, $data)) {
                    $listing->{$field} = $data[$field];
                }
            }

            if ($listing->status === ListingStatus::Rejected) {
                $listing->status = ListingStatus::Draft;
                $listing->rejection_reason = null;
                $listing->rejected_at = null;
            }

            $listing->save();

            if (array_key_exists('attributes', $data)) {
                $this->syncAttributes($listing, $listing->category, $data['attributes'] ?? [], validateRequired: false);
            }

            return $listing->load(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);
        });
    }

    /**
     * Owner submits a DRAFT or REJECTED listing for admin review.
     * All required category attributes must be present at this point.
     */
    public function submitForReview(Listing $listing): Listing
    {
        if (! $listing->isEditableByOwner()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or rejected listings can be submitted for review.'],
            ]);
        }

        return DB::transaction(function () use ($listing): Listing {
            $this->syncAttributes($listing, $listing->category, $this->currentAttributeMap($listing), validateRequired: true);

            if (empty($listing->title) || $listing->price === null || $listing->location_id === null) {
                throw ValidationException::withMessages([
                    'listing' => ['Title, price and location are required before submitting for review.'],
                ]);
            }

            $listing->status = ListingStatus::PendingReview;
            $listing->submitted_at = now();
            $listing->rejection_reason = null;
            $listing->rejected_at = null;
            $listing->save();

            return $listing->fresh(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);
        });
    }

    /**
     * Admin approves a PENDING_REVIEW listing, making it publicly visible.
     */
    public function approve(Listing $listing): Listing
    {
        if ($listing->status !== ListingStatus::PendingReview) {
            throw ValidationException::withMessages([
                'status' => ['Only listings pending review can be approved.'],
            ]);
        }

        $listing->status = ListingStatus::Published;
        $listing->published_at = now();
        $listing->rejection_reason = null;
        $listing->save();

        return $listing->fresh(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);
    }

    /**
     * Admin rejects a PENDING_REVIEW listing with a required reason.
     * The owner can edit and resubmit afterwards.
     */
    public function reject(Listing $listing, string $reason): Listing
    {
        if ($listing->status !== ListingStatus::PendingReview) {
            throw ValidationException::withMessages([
                'status' => ['Only listings pending review can be rejected.'],
            ]);
        }

        $listing->status = ListingStatus::Rejected;
        $listing->rejection_reason = $reason;
        $listing->rejected_at = now();
        $listing->save();

        return $listing->fresh(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);
    }

    /**
     * Archive a listing (owner or admin). Archived listings never appear
     * publicly again and cannot be edited or resubmitted.
     */
    public function archive(Listing $listing): Listing
    {
        $listing->status = ListingStatus::Archived;
        $listing->archived_at = now();
        $listing->save();

        return $listing->fresh(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);
    }

    public function markSold(Listing $listing): Listing
    {
        return $this->setAvailability($listing, AvailabilityStatus::Sold);
    }

    public function markRented(Listing $listing): Listing
    {
        return $this->setAvailability($listing, AvailabilityStatus::Rented);
    }

    public function markAvailable(Listing $listing): Listing
    {
        return $this->setAvailability($listing, AvailabilityStatus::Available);
    }

    private function setAvailability(Listing $listing, AvailabilityStatus $status): Listing
    {
        if (! $listing->isPublished()) {
            throw ValidationException::withMessages([
                'status' => ['Only published listings have an availability status.'],
            ]);
        }

        $listing->availability_status = $status;
        $listing->save();

        return $listing->fresh(['category', 'location', 'attributeValues.definition', 'media', 'user', 'business']);
    }

    /**
     * Finds (or creates, if it genuinely does not exist yet) a location
     * record instead of always inserting a new row for the same place.
     */
    private function resolveLocation(?array $locationData): ?Location
    {
        if (empty($locationData) || empty($locationData['city'])) {
            return null;
        }

        $country = $locationData['country'] ?? 'IN';
        $state = $locationData['state'] ?? null;
        $city = $locationData['city'];
        $area = $locationData['area'] ?? null;
        $locality = $locationData['locality'] ?? null;

        return Location::firstOrCreate(
            [
                'country' => $country,
                'state' => $state,
                'city' => $city,
                'area' => $area,
                'locality' => $locality,
            ],
            [
                'postal_code' => $locationData['postal_code'] ?? null,
                'latitude' => $locationData['latitude'] ?? null,
                'longitude' => $locationData['longitude'] ?? null,
            ]
        );
    }

    private function currentAttributeMap(Listing $listing): array
    {
        return $listing->attributeValues()
            ->with('definition')
            ->get()
            ->mapWithKeys(fn (ListingAttributeValue $value) => [$value->definition->key => $value->value])
            ->all();
    }

    private function syncAttributes(Listing $listing, Category $category, array $attributes, bool $validateRequired): void
    {
        $definitions = $category->attributeDefinitions()->get()->keyBy('key');
        $errors = [];

        if ($validateRequired) {
            foreach ($definitions as $key => $definition) {
                $value = $attributes[$key] ?? null;

                if ($definition->is_required && ($value === null || $value === '')) {
                    $errors["attributes.{$key}"] = ["The {$definition->label} field is required."];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $listing->attributeValues()->delete();

        foreach ($attributes as $key => $value) {
            if (! $definitions->has($key) || $value === null || $value === '') {
                continue;
            }

            ListingAttributeValue::create([
                'listing_id' => $listing->id,
                'category_attribute_definition_id' => $definitions[$key]->id,
                'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            ]);
        }
    }
}
