<?php

namespace App\Services\Ai\Extraction;

use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Matches an extracted place name against the `locations` table.
 *
 * The brief's rule — "never create a location solely because AI generated it" — is
 * honoured strictly here: this class only ever *reads*. Nothing in it inserts.
 *
 * That is a different judgement from the category rule, and the difference is worth
 * stating. A category is a taxonomy the marketplace controls, so an unknown one is
 * always an error. A city is a fact about India, and the platform is pan-India with an
 * open location table — a buyer in a town the seed data missed is a legitimate user,
 * not a data-quality problem. So the resolver reports "not in the database yet" and
 * hands the decision upward: the requirement-draft path may then create the row
 * through RequirementService (the same code the manual form uses, and only from a
 * place name the *buyer* typed), while search simply filters on the text.
 *
 * No city, state or region is hardcoded anywhere in this class.
 */
class LocationResolver
{
    /**
     * Well-known aliases and metro clusters.
     *
     * These are spelling and colloquialism mappings, not a location whitelist — every
     * value still has to exist in the database to resolve. Included because "Bangalore"
     * and "Bengaluru" are the same city to a buyer, and "Delhi NCR" is how people
     * actually write a region that spans three states.
     *
     * @var array<string, array<int, string>>
     */
    private const ALIASES = [
        'bangalore' => ['bengaluru'],
        'bengaluru' => ['bangalore'],
        'bombay' => ['mumbai'],
        'mumbai' => ['bombay'],
        'calcutta' => ['kolkata'],
        'kolkata' => ['calcutta'],
        'madras' => ['chennai'],
        'chennai' => ['madras'],
        'poona' => ['pune'],
        'pune' => ['poona'],
        'gurgaon' => ['gurugram'],
        'gurugram' => ['gurgaon'],
        'trivandrum' => ['thiruvananthapuram'],
        'thiruvananthapuram' => ['trivandrum'],
        'baroda' => ['vadodara'],
        'vadodara' => ['baroda'],
        'mysore' => ['mysuru'],
        'mysuru' => ['mysore'],
        // Regional clusters expand to their constituent cities so "Delhi NCR" finds
        // suppliers in Noida.
        'delhi ncr' => ['delhi', 'new delhi', 'noida', 'gurugram', 'gurgaon', 'ghaziabad', 'faridabad'],
        'ncr' => ['delhi', 'new delhi', 'noida', 'gurugram', 'gurgaon', 'ghaziabad', 'faridabad'],
        'new delhi' => ['delhi'],
        'delhi' => ['new delhi'],
        'tricity' => ['chandigarh', 'mohali', 'panchkula'],
        'mmr' => ['mumbai', 'thane', 'navi mumbai'],
    ];

    /**
     * @return array{
     *     location: Location|null,
     *     city: string|null,
     *     state: string|null,
     *     exists_in_database: bool,
     *     candidates: array<int, array{city: string, state: string|null}>,
     *     reason: string
     * }
     */
    public function resolve(?string $city, ?string $state = null): array
    {
        $city = is_string($city) ? trim($city) : '';

        if ($city === '') {
            return $this->miss(null, $state, 'No location was mentioned.');
        }

        $needle = mb_strtolower($city);

        // Exact city match, optionally narrowed by state.
        $exact = $this->queryByCity($needle, $state)->first();

        if ($exact !== null) {
            return $this->hit($exact, 'Matched a known location.');
        }

        // Aliases and cluster expansion.
        foreach (self::ALIASES[$needle] ?? [] as $alias) {
            $match = $this->queryByCity($alias, $state)->first();

            if ($match !== null) {
                return $this->hit($match, sprintf('Matched "%s" as an alternative name for "%s".', $match->city, $city));
            }
        }

        // Partial match, for "Noida Sector 62" or a trailing state name.
        $partial = Location::query()
            ->whereRaw('lower(city) like ?', ['%'.$needle.'%'])
            ->orWhereRaw('? like \'%\' || lower(city) || \'%\'', [$needle])
            ->orderByRaw('length(city)')
            ->first();

        if ($partial !== null) {
            return $this->hit($partial, sprintf('Matched "%s" from "%s".', $partial->city, $city));
        }

        /*
         * Not in the database. This is not an error and not a rejection — the city name
         * came from the buyer, and India has more towns than any seed file. The caller
         * decides: the draft path passes the name to RequirementService, which reuses
         * or inserts the row exactly as the manual form does; search filters on the
         * text and simply finds nothing.
         */
        return $this->miss($city, $state, sprintf(
            '"%s" is not in the location table yet. It can still be used — it will be recorded the same way the manual form records a new place.',
            $city
        ));
    }

    /**
     * Cities the marketplace already has activity in, for suggestions.
     *
     * Ordered by real supplier presence so the list reflects where a buyer can
     * actually be served, rather than alphabetical seed order.
     *
     * @return Collection<int, array{city: string, state: string|null, businesses: int}>
     */
    public function activeCities(int $limit = 12): Collection
    {
        return Location::query()
            ->select('locations.city', 'locations.state')
            ->selectRaw('count(distinct businesses.id) as businesses')
            ->leftJoin('businesses', 'businesses.location_id', '=', 'locations.id')
            ->groupBy('locations.city', 'locations.state')
            ->orderByDesc('businesses')
            ->orderBy('locations.city')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'city' => (string) $row->city,
                'state' => $row->state,
                'businesses' => (int) $row->businesses,
            ]);
    }

    private function queryByCity(string $city, ?string $state): Builder
    {
        $query = Location::query()->whereRaw('lower(city) = ?', [$city]);

        if (is_string($state) && trim($state) !== '') {
            // Narrowing, not requiring: a state that does not match should not veto a
            // city that does, because buyers get states wrong far more often than cities.
            $query->where(function ($q) use ($state): void {
                $q->whereRaw('lower(state) = ?', [mb_strtolower(trim($state))])->orWhereNull('state');
            });
        }

        // Prefer the least specific row for a city — the bare "Pune" over
        // "Pune / Hinjewadi / Phase 2" — since an extracted place name is city-level.
        return $query->orderByRaw('area IS NOT NULL')->orderByRaw('locality IS NOT NULL');
    }

    /** @return array<string, mixed> */
    private function hit(Location $location, string $reason): array
    {
        return [
            'location' => $location,
            'city' => $location->city,
            'state' => $location->state,
            'exists_in_database' => true,
            'candidates' => [],
            'reason' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    private function miss(?string $city, ?string $state, string $reason): array
    {
        return [
            'location' => null,
            'city' => $city,
            'state' => is_string($state) && trim($state) !== '' ? trim($state) : null,
            'exists_in_database' => false,
            'candidates' => $this->activeCities(6)
                ->map(fn (array $row): array => ['city' => $row['city'], 'state' => $row['state']])
                ->all(),
            'reason' => $reason,
        ];
    }
}
