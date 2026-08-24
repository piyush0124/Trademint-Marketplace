<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Reference locations — twenty Indian cities across sixteen states.
 *
 * Two properties matter here and both are load-bearing:
 *
 * 1. No city is an application default. Nothing in the codebase branches on a
 *    city name, and deleting any single row here — Kolkata and Delhi included —
 *    must leave the marketplace fully functional. The spread exists so that a
 *    reviewer can see the product is not built around one metro.
 *
 * 2. Areas are seeded per city, because `locations` is a (city, area) grain, not
 *    a city grain. Businesses register against an area and quote against a
 *    service-area list, so a city with no areas cannot host a supplier.
 *
 * Idempotent by firstOrCreate on the natural key. Re-running never duplicates.
 */
class LocationSeeder extends Seeder
{
    /**
     * City naming follows current official usage (Bengaluru, not Bangalore).
     * Earlier development databases may still hold a legacy "Bangalore" row from
     * a previous version of this seeder; it is harmless, and a fresh
     * `migrate:fresh --seed` produces only the canonical twenty below.
     */
    private const CITIES = [
        // --- National Capital Region ---
        ['state' => 'Delhi', 'city' => 'New Delhi', 'areas' => ['Connaught Place', 'Dwarka', 'Okhla', 'Rohini']],
        ['state' => 'Uttar Pradesh', 'city' => 'Noida', 'areas' => ['Sector 62', 'Sector 18', 'Sector 135']],
        ['state' => 'Uttar Pradesh', 'city' => 'Ghaziabad', 'areas' => ['Indirapuram', 'Vaishali', 'Sahibabad']],
        ['state' => 'Haryana', 'city' => 'Gurugram', 'areas' => ['Cyber City', 'Sohna Road', 'Udyog Vihar']],

        // --- North ---
        ['state' => 'Uttar Pradesh', 'city' => 'Lucknow', 'areas' => ['Gomti Nagar', 'Hazratganj', 'Amausi']],
        ['state' => 'Rajasthan', 'city' => 'Jaipur', 'areas' => ['Malviya Nagar', 'Vaishali Nagar', 'Sitapura']],
        ['state' => 'Chandigarh', 'city' => 'Chandigarh', 'areas' => ['Industrial Area Phase 1', 'Sector 17', 'Mohali']],

        // --- West ---
        ['state' => 'Maharashtra', 'city' => 'Mumbai', 'areas' => ['Andheri East', 'Bandra Kurla Complex', 'Lower Parel', 'Bhiwandi']],
        ['state' => 'Maharashtra', 'city' => 'Pune', 'areas' => ['Hinjewadi', 'Kharadi', 'Chakan', 'Shivajinagar']],
        ['state' => 'Gujarat', 'city' => 'Ahmedabad', 'areas' => ['Prahlad Nagar', 'Changodar', 'Naroda']],
        ['state' => 'Gujarat', 'city' => 'Surat', 'areas' => ['Adajan', 'Hazira', 'Sachin GIDC']],
        ['state' => 'Madhya Pradesh', 'city' => 'Indore', 'areas' => ['Vijay Nagar', 'Pithampur', 'Rau']],

        // --- South ---
        ['state' => 'Karnataka', 'city' => 'Bengaluru', 'areas' => ['Koramangala', 'Whitefield', 'Peenya', 'Electronic City']],
        ['state' => 'Telangana', 'city' => 'Hyderabad', 'areas' => ['Gachibowli', 'Banjara Hills', 'Jeedimetla']],
        ['state' => 'Tamil Nadu', 'city' => 'Chennai', 'areas' => ['Guindy', 'Ambattur', 'OMR', 'Sriperumbudur']],
        ['state' => 'Kerala', 'city' => 'Kochi', 'areas' => ['Kakkanad', 'Edappally', 'Kalamassery']],

        // --- East ---
        ['state' => 'West Bengal', 'city' => 'Kolkata', 'areas' => ['New Town', 'Salt Lake', 'Howrah']],
        ['state' => 'Odisha', 'city' => 'Bhubaneswar', 'areas' => ['Patia', 'Chandaka', 'Khandagiri']],
        ['state' => 'Bihar', 'city' => 'Patna', 'areas' => ['Boring Road', 'Patliputra', 'Fatuha']],
        ['state' => 'Jharkhand', 'city' => 'Ranchi', 'areas' => ['Hinoo', 'Kokar', 'Namkum']],
    ];

    public function run(): void
    {
        foreach (self::CITIES as $entry) {
            foreach ($entry['areas'] as $area) {
                Location::firstOrCreate([
                    'country' => 'IN',
                    'state' => $entry['state'],
                    'city' => $entry['city'],
                    'area' => $area,
                    'locality' => null,
                ]);
            }
        }
    }

    /** The canonical city names, for seeders and tests that need to spread data. */
    public static function cityNames(): array
    {
        return array_column(self::CITIES, 'city');
    }
}
