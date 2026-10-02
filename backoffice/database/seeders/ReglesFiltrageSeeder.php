<?php

namespace Database\Seeders;

use App\Enums\TypeRegleFiltrage;
use App\Models\RegleFiltrage;
use Illuminate\Database\Seeder;

/**
 * Mots de départ du filtre « spectacle vivant » (repris du POC, COLLECTE §5). Modifiables dans le BO.
 */
class ReglesFiltrageSeeder extends Seeder
{
    public const INCLURE = [
        'theatre', 'spectacle', 'humour', 'humoriste', 'one man', 'one woman', 'stand up', 'comedie',
        'cafe theatre', 'concert', 'opera', 'operette', 'danse', 'ballet', 'cirque', 'marionnette',
        'conte', 'cabaret', 'magie', 'magicien', 'impro', 'mime', 'clown', 'arts de la rue', 'recital',
        'chanson', 'jazz', 'piece', 'comedie musicale',
    ];

    public const EXCLURE = [
        'exposition', 'visite', 'atelier', 'conference', 'marche', 'brocante', 'vide grenier', 'salon',
        'randonnee', 'sport', 'match', 'cinema', 'projection', 'stage', 'club lecture', 'the dansant',
        'boum', 'baleti', 'soiree salsa', 'science', 'mediatheque', 'parcours de l art',
    ];

    public function run(): void
    {
        foreach ([TypeRegleFiltrage::Inclure->value => self::INCLURE, TypeRegleFiltrage::Exclure->value => self::EXCLURE] as $type => $mots) {
            foreach ($mots as $mot) {
                RegleFiltrage::firstOrCreate(['type' => $type, 'mot' => $mot], ['actif' => true]);
            }
        }
    }
}
