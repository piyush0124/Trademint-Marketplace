<?php

namespace App\Services;

use App\Enums\RequirementStatus;
use App\Models\Category;
use App\Models\Location;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns all Requirement workflow business logic, mirroring ListingService:
 * controllers stay thin and only translate HTTP <-> these methods; nothing
 * here trusts a status value sent from the client — every transition is an
 * explicit method with its own pre-condition checks.
 */
class RequirementService
{
    private const RELATIONS = ['category', 'location', 'customer'];

    public function __construct(private readonly MatchingService $matchingService) {}

    /** Create a brand new requirement in DRAFT. Never publishes automatically. */
    public function createDraft(User $customer, array $data): Requirement
    {
        return DB::transaction(function () use ($customer, $data): Requirement {
            $category = Category::where('id', $data['category_id'])->where('is_active', true)->firstOrFail();
            $location = $this->resolveLocation($data['location'] ?? null);

            $requirement = Requirement::create([
                'customer_id' => $customer->id,
                'category_id' => $category->id,
                'location_id' => $location?->id,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'quantity' => $data['quantity'] ?? null,
                'unit' => $data['unit'] ?? null,
                'budget_min' => $data['budget_min'] ?? null,
                'budget_max' => $data['budget_max'] ?? null,
                'required_date' => $data['required_date'] ?? null,
                'urgency' => $data['urgency'] ?? 'normal',
                'status' => RequirementStatus::Draft,
            ]);

            return $requirement->load(self::RELATIONS);
        });
    }

    /**
     * Update a requirement while it is still a draft. Only ever called
     * after RequirementPolicy::update has already confirmed draft status,
     * but re-checked defensively rather than assumed.
     */
    public function updateDraft(Requirement $requirement, array $data): Requirement
    {
        return DB::transaction(function () use ($requirement, $data): Requirement {
            if (array_key_exists('category_id', $data)) {
                $category = Category::where('id', $data['category_id'])->where('is_active', true)->firstOrFail();
                $requirement->category_id = $category->id;
            }

            if (array_key_exists('location', $data)) {
                $location = $this->resolveLocation($data['location']);
                $requirement->location_id = $location?->id;
            }

            // Only touch a field if the client actually sent that key. A
            // present key — even an explicit null — must be applied (e.g.
            // clearing the description); a missing key must not overwrite
            // the existing value. Same reasoning as ListingService::updateDraft.
            foreach (['title', 'description', 'quantity', 'unit', 'budget_min', 'budget_max', 'required_date', 'urgency'] as $field) {
                if (array_key_exists($field, $data)) {
                    $requirement->{$field} = $data[$field];
                }
            }

            $requirement->save();

            return $requirement->fresh(self::RELATIONS);
        });
    }

    /**
     * Customer publishes a DRAFT requirement, making it visible to
     * businesses so the marketplace can start finding matches. Everything
     * a match needs must be present at this point — draft creation is
     * deliberately loose, publishing is where completeness is enforced.
     */
    public function publish(Requirement $requirement): Requirement
    {
        if (! $requirement->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft requirements can be published.'],
            ]);
        }

        if (empty($requirement->title) || $requirement->quantity === null || $requirement->location_id === null || $requirement->required_date === null) {
            throw ValidationException::withMessages([
                'requirement' => ['Title, quantity, location and required date are required before publishing.'],
            ]);
        }

        if ($requirement->budget_min !== null && $requirement->budget_max !== null && $requirement->budget_min > $requirement->budget_max) {
            throw ValidationException::withMessages([
                'budget_max' => ['Maximum budget must be greater than or equal to the minimum budget.'],
            ]);
        }

        $requirement->status = RequirementStatus::Published;
        $requirement->published_at = now();
        $requirement->save();

        // Phase 4: the marketplace finds the businesses, the customer doesn't
        // go looking. Publishing is therefore the trigger for a matching run,
        // which may move the requirement on to Matching if it finds anyone.
        // Deliberately "quiet": a problem inside the engine must not turn a
        // successful publish into a failure — the run can simply be repeated.
        $this->matchingService->generateMatchesQuietly($requirement);

        return $requirement->fresh(self::RELATIONS);
    }

    /**
     * Customer cancels a requirement at any stage short of closed/cancelled.
     * Deliberately does not delete the row — the customer's dashboard keeps
     * a full history of what they've requested.
     */
    public function cancel(Requirement $requirement): Requirement
    {
        if (! $requirement->isCancellable()) {
            throw ValidationException::withMessages([
                'status' => ['This requirement can no longer be cancelled.'],
            ]);
        }

        $requirement->status = RequirementStatus::Cancelled;
        $requirement->cancelled_at = now();
        $requirement->save();

        return $requirement->fresh(self::RELATIONS);
    }

    /**
     * Finds (or creates, if it genuinely does not exist yet) a location
     * record instead of always inserting a new row for the same place.
     * Deliberately independent of ListingService::resolveLocation — same
     * approach today, but the two workflows are free to diverge later
     * without one silently affecting the other.
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
}
