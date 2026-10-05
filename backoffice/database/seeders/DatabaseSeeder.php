<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Données de référence. Les villes s'importent avec `php artisan villes:importer`,
     * les comptes admin avec `php artisan admin:creer`.
     */
    public function run(): void
    {
        $this->call([
            GenresSeeder::class,
            ParametresSeeder::class,
            SourcesSeeder::class,
            ReglesFiltrageSeeder::class,
            MotsGenresSeeder::class,
        ]);
    }
}
