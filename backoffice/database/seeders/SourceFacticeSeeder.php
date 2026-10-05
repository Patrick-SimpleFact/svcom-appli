<?php

namespace Database\Seeders;

use App\Enums\TypeAccesSource;
use App\Enums\TypeLienSource;
use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Source de démonstration (poste de développement uniquement) : `php artisan db:seed --class=SourceFacticeSeeder`.
 */
class SourceFacticeSeeder extends Seeder
{
    public function run(): void
    {
        Source::firstOrCreate(['code' => 'factice'], [
            'nom' => 'Démonstration (factice)',
            'type_acces' => TypeAccesSource::Api,
            'licence' => 'Données inventées',
            'type_lien' => TypeLienSource::Direct,
            'actif' => false,
            'config' => ['simuler_echec' => false],
            'remarques' => 'Source de test : huit annonces d’exemple (cinq gardées, une à trier, une exclue, une illisible). Cocher « simuler un échec » pour voir les nouveaux essais.',
        ]);

        // Seconde billetterie de démonstration : les mêmes séances écrites autrement (déduplication, K06).
        Source::firstOrCreate(['code' => 'factice_bis'], [
            'nom' => 'Démonstration bis (factice)',
            'type_acces' => TypeAccesSource::Api,
            'licence' => 'Données inventées',
            'type_lien' => TypeLienSource::Direct,
            'actif' => false,
            'config' => ['simuler_echec' => false, 'jeu' => 'bis'],
            'remarques' => 'Source de test : trois séances déjà vendues par « Démonstration », écrites autrement (doublons). À lancer après elle.',
        ]);
    }
}
