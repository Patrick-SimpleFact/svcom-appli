<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Profil;
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
            'profil_id' => fn () => Profil::superAdmin()->value('id'),
        ];
    }

    /** Admin ayant déjà configuré sa double authentification. */
    public function avecDoubleAuthentification(): static
    {
        return $this->state(fn () => [
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
    }

    /** Admin qui suit un modèle limité à ces menus (classes de ressources ou de pages). */
    public function avecMenus(array $menus): static
    {
        return $this->state(fn () => ['profil_id' => Profil::create(['nom' => fake()->unique()->word(), 'menus' => $menus])->id]);
    }

    public function inactif(): static
    {
        return $this->state(fn () => ['actif' => false]);
    }
}
