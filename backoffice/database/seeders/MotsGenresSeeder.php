<?php

namespace Database\Seeders;

use App\Models\Genre;
use App\Models\MotGenre;
use Illuminate\Database\Seeder;

/**
 * Mots de départ pour reconnaître le genre dans le titre ou la description (COLLECTE §6, sous-genres de F2.3).
 * Écrits sans accents ni majuscules. Modifiables dans le BO ; relancer ce seeder n'écrase rien.
 */
class MotsGenresSeeder extends Seeder
{
    public const MOTS = [
        'theatre' => ['theatre', 'piece', 'comedie', 'boulevard', 'cafe theatre', 'theatre d objet', 'lecture', 'tragedie', 'vaudeville', 'drame'],
        'humour' => ['humour', 'humoriste', 'one man show', 'one woman show', 'one man', 'one woman', 'stand up', 'plateau d humoristes', 'imitateur', 'imitation', 'sketch'],
        'concert' => ['concert', 'variete', 'rock', 'jazz', 'musique classique', 'musiques du monde', 'electro', 'tribute', 'chanson', 'orchestre', 'symphonique', 'blues', 'rap', 'pop', 'quatuor'],
        'comedie-musicale-cabaret' => ['comedie musicale', 'cabaret', 'music hall', 'revue', 'diner spectacle'],
        'danse' => ['danse', 'ballet', 'hip hop', 'flamenco', 'danses traditionnelles', 'tango'],
        'cirque-magie' => ['cirque', 'arts de la piste', 'clown', 'magie', 'magicien', 'mentalisme', 'mentaliste', 'acrobatie', 'jonglage'],
        'opera-lyrique' => ['opera', 'operette', 'recital lyrique', 'lyrique'],
        'autres' => ['marionnette', 'conte', 'conteur', 'arts de la rue', 'performance', 'pluridisciplinaire'],
    ];

    /** Marqueur « Jeune public » seul (le genre vient d'ailleurs). */
    public const JEUNE_PUBLIC = ['jeune public', 'enfant', 'famille', 'tout petits', 'jeunesse'];

    public function run(): void
    {
        $genres = Genre::pluck('id', 'slug');

        foreach (self::MOTS as $slug => $mots) {
            foreach ($mots as $mot) {
                MotGenre::firstOrCreate(['mot' => $mot], ['genre_id' => $genres[$slug], 'actif' => true]);
            }
        }

        foreach (self::JEUNE_PUBLIC as $mot) {
            MotGenre::firstOrCreate(['mot' => $mot], ['genre_id' => null, 'jeune_public' => true, 'actif' => true]);
        }
    }
}
