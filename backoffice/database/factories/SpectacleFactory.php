<?php

namespace Database\Factories;

use App\Models\Genre;
use App\Models\Spectacle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Spectacle>
 */
class SpectacleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'titre' => ucfirst(fake()->words(fake()->numberBetween(2, 5), true)),
            'description' => fake()->paragraph(),
            'genre_id' => Genre::query()->inRandomOrder()->value('id') ?? Genre::create(['slug' => 'theatre', 'libelle' => 'Théâtre', 'ordre' => 1])->id,
            'duree_minutes' => fake()->randomElement([60, 75, 80, 90, 120]),
        ];
    }
}
