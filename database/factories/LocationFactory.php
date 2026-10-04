<?php

namespace Database\Factories;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'type' => fake()->randomElement(LocationType::cases()),
            'is_active' => true,
        ];
    }
}
