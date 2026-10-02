<?php

namespace Database\Factories;

use App\Enums\PrecisionPosition;
use App\Enums\TypeLieu;
use App\Models\Lieu;
use App\Support\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lieu>
 */
class LieuFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nom' => 'Théâtre '.fake()->unique()->lastName(),
            'type' => TypeLieu::Theatre,
            'adresse' => fake()->streetAddress(),
            'code_postal' => '84000',
            'position' => new Point(43.9493 + fake()->randomFloat(4, -0.01, 0.01), 4.8057 + fake()->randomFloat(4, -0.01, 0.01)),
            'precision_position' => PrecisionPosition::Exacte,
            'fuseau_horaire' => 'Europe/Paris',
        ];
    }
}
