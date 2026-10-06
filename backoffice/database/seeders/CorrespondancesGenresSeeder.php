<?php

namespace Database\Seeders;

use App\Models\CorrespondanceGenre;
use App\Models\Genre;
use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Correspondances de départ vers les genres de l'app, pour les sources aux catégories propres et peu nombreuses :
 * types de l'ontologie DATAtourisme (N03), étiquettes de Que faire à Paris (N06).
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

    public const PARIS_QFAP = [
        'Théâtre' => 'theatre',
        'Concert' => 'concert',
        'Danse' => 'danse',
        'Humour' => 'humour',
        'Cirque' => 'cirque-magie',
        'Spectacle musical' => 'comedie-musicale-cabaret',
    ];

    public function run(): void
    {
        $genres = Genre::pluck('id', 'slug');

        foreach (['datatourisme' => self::DATATOURISME, 'paris_qfap' => self::PARIS_QFAP] as $code => $correspondances) {
            $source = Source::firstWhere('code', $code);

            if ($source === null) {
                continue;
            }

            foreach ($correspondances as $categorie => $genre) {
                CorrespondanceGenre::firstOrCreate(['source_id' => $source->id, 'categorie_source' => $categorie], ['genre_id' => $genres[$genre]]);
            }
        }
    }
}
