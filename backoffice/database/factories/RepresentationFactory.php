<?php

namespace Database\Factories;

use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Spectacle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Representation>
 */
class RepresentationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spectacle_id' => Spectacle::factory(),
            'lieu_id' => Lieu::factory(),
            'type' => TypeRepresentation::Seance,
            'debut' => now('Europe/Paris')->addDays(fake()->numberBetween(0, 30))->setTime(fake()->randomElement([19, 20, 21]), fake()->randomElement([0, 30])),
            'prix_min' => fake()->randomElement([null, 12, 15, 18, 22]),
            'statut' => StatutRepresentation::Programmee,
        ];
    }
}
