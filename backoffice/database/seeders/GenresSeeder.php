<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;

/**
 * Les 8 genres de l'app (F2.3). « Tout » et « Jeune public » sont des onglets, pas des genres.
 */
class GenresSeeder extends Seeder
{
    public const GENRES = [
        ['slug' => 'theatre', 'libelle' => 'Théâtre'],
        ['slug' => 'humour', 'libelle' => 'Humour'],
        ['slug' => 'concert', 'libelle' => 'Concert'],
        ['slug' => 'comedie-musicale-cabaret', 'libelle' => 'Comédie musicale & cabaret'],
        ['slug' => 'danse', 'libelle' => 'Danse'],
        ['slug' => 'cirque-magie', 'libelle' => 'Cirque & magie'],
        ['slug' => 'opera-lyrique', 'libelle' => 'Opéra & lyrique'],
        ['slug' => 'autres', 'libelle' => 'Autres'],
    ];

    public function run(): void
    {
        foreach (self::GENRES as $ordre => $genre) {
            Genre::updateOrCreate(['slug' => $genre['slug']], [...$genre, 'ordre' => $ordre + 1]);
        }
    }
}
