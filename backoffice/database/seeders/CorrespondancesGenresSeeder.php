<?php

namespace Database\Seeders;

use App\Models\CorrespondanceGenre;
use App\Models\Genre;
use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Correspondances de départ des types de l'ontologie DATAtourisme vers les genres de l'app (N03).
 * Modifiables dans le BO ; relancer ce seeder n'écrase rien.
 */
class CorrespondancesGenresSeeder extends Seeder
{
    public const DATATOURISME = [
        'TheaterEvent' => 'theatre',
        'Concert' => 'concert',
        'Recital' => 'concert',
        'DanceEvent' => 'danse',
        'CircusEvent' => 'cirque-magie',
        'Opera' => 'opera-lyrique',
        'ComedyEvent' => 'humour',
        'PuppetShow' => 'autres',
        'StreetArtShow' => 'autres',
    ];

    public function run(): void
    {
        $source = Source::firstWhere('code', 'datatourisme');
        $genres = Genre::pluck('id', 'slug');

        if ($source === null) {
            return;
        }

        foreach (self::DATATOURISME as $categorie => $genre) {
            CorrespondanceGenre::firstOrCreate(['source_id' => $source->id, 'categorie_source' => $categorie], ['genre_id' => $genres[$genre]]);
        }
    }
}
