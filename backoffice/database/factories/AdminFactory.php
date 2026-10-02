<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'nom' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'actif' => true,
        ];
    }

    /** Admin ayant déjà configuré sa double authentification. */
    public function avecDoubleAuthentification(): static
    {
        return $this->state(fn () => [
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
    }

    public function inactif(): static
    {
        return $this->state(fn () => ['actif' => false]);
    }
}
