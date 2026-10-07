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
        // catégories des sources en anglais (Ticketmaster « Arts & Theatre », DATAtourisme « TheaterEvent »…)
        'theater', 'dance', 'music', 'comedy',
    ];

    public const EXCLURE = [
        'exposition', 'visite', 'atelier', 'conference', 'brocante', 'vide grenier', 'salon',
        'randonnee', 'sport', 'match', 'cinema', 'projection', 'stage', 'club lecture', 'the dansant',
        'boum', 'baleti', 'soiree salsa', 'science', 'mediatheque', 'parcours de l art',
        'bibliotheque', 'fete de la science',
        // catégories Fnac hors spectacle (validé par Patrick le 05/10/2026, N02)
        'parc d attraction', 'aquarium', 'musee', 'tourisme et sejours', 'zoo', 'croisiere', // pas « tourisme » seul : la Fnac classe les cabarets en « Tourisme loisirs › Cabaret »
        // marchés : expressions ciblées, pas « marche » seul (il écartait « Ça marche », « Marche forcée », « La loi du marché »… ; N07, 07/10/2026)
        'marche de noel', 'marche de producteurs', 'marche des producteurs', 'marche artisanal', 'marche gourmand',
        'marche nocturne', 'marche bio', 'marche du terroir', 'marche aux', 'marche des createurs', 'marche de createurs',
        'marche couvert', 'marche d automne', 'marche d ete', 'marche hebdomadaire', 'marche paysan', 'marche fermier',
        'marche du monde', 'marche des brasseurs', 'petit marche',
        // billets d'entrée de musées et d'expositions vendus par Ticketmaster (« ANDY WARHOL - ENTRÉE SIMPLE », P02, 07/10/2026)
        'entree simple',
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
