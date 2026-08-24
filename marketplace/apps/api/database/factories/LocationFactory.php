<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            // Faker's own city/state generator — never a fixed value, so
            // factory-made data can't accidentally re-introduce a
            // single-city assumption.
            'country' => 'IN',
            'state' => fake()->state(),
            'city' => fake()->city(),
            'area' => fake()->streetName(),
            'locality' => null,
            'postal_code' => fake()->numerify('######'),
            'latitude' => fake()->latitude(8.0, 35.0),
            'longitude' => fake()->longitude(68.0, 97.0),
        ];
    }
}
