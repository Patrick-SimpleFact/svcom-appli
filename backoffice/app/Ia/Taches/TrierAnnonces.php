<?php

namespace App\Ia\Taches;

use App\Actions\DeciderTri;
use App\Enums\FileATraiter;
use App\Enums\IssueFiltrage;
use App\Models\ElementATraiter;
use App\Models\PropositionIa;
use App\Support\Texte;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * File « À trier » : l'annonce est-elle un spectacle vivant à publier (« garder ») ou non (« exclure ») ?
 * Appliqué par DeciderTri, comme une décision de Patrick : le tri automatique la respectera toujours.
 */
class TrierAnnonces extends TacheIa
{
    /** Un titre qui évoque une activité, gardée quand même par les modèles : à vérifier par Patrick. */
    public const MOTS_A_VERIFIER_SI_GARDE = ['bal', 'baleti', 'fest noz', 'the dansant', 'stage', 'atelier', 'ateliers', 'cours', 'expo', 'exposition',
        'visite', 'conference', 'marche', 'salon', 'murder', 'escape', 'jeu de piste', 'loto', 'bingo', 'projection', 'film', 'brocante',
        'vide grenier', 'randonnee', 'initiation', 'masterclass'];

    /** Un titre qui évoque un spectacle, exclu quand même par les modèles : à vérifier par Patrick. */
    public const MOTS_A_VERIFIER_SI_EXCLU = ['theatre', 'concert', 'spectacle', 'humour', 'humoriste', 'one man', 'stand up', 'comedie', 'danse',
        'ballet', 'cirque', 'magie', 'opera', 'chorale', 'orchestre', 'recital', 'festival'];

    public function file(): FileATraiter
    {
        return FileATraiter::ATrier;
    }

    public function cleMode(): string
    {
        return 'ia_mode_a_trier';
    }

    public function messages(Collection $elements): array
    {
        $systeme = $this->socle()."\n\n".<<<'TXT'
            TÂCHE : dire si une annonce est un SPECTACLE VIVANT à publier dans l'application (« garder ») ou non (« exclure »).
            Une annonce gardée sera publiée ; une annonce exclue disparaît définitivement de l'application. En cas de doute, confiance basse : Patrick décidera.

            DÉFINITION : un spectacle vivant est une représentation donnée en direct, devant un public, par des artistes (comédiens, musiciens, danseurs, circassiens, humoristes, magiciens, conteurs…), avec une date et un lieu.

            GARDER :
            - théâtre, humour, concerts de tous styles, danse, cirque, magie, hypnose de scène, opéra, comédies musicales, cabaret, marionnettes, contes et spectacles jeune public, arts de la rue, improvisation ;
            - festivals de spectacles (une soirée ou un concert de festival) ;
            - spectacles équestres, sur glace, sons et lumières avec artistes en scène ;
            - ciné-concerts (orchestre en direct sur un film) ;
            - soirées DJ, clubbing et live électro annoncés comme concerts ;
            - lectures-spectacles et lectures musicales données par des artistes ;
            - heures du conte et lectures pour enfants, y compris en bibliothèque ou médiathèque : ce sont des spectacles jeune public (choix de Patrick du 21/10/2026) ;
            - sorties de résidence et restitutions publiques où des artistes jouent devant du public.

            EXCLURE :
            - expositions, visites, musées, vernissages, finissages, parcs d'attractions, zoos, aquariums, croisières ;
            - conférences, débats, colloques, rencontres, masterclass, formations, permanences ;
            - ateliers, cours, stages (même de danse, de théâtre ou de chant), « baptêmes » d'impro ;
            - cinéma : projections, avant-premières, ciné-clubs (sauf ciné-concert) ;
            - jeux : escape games, jeux de piste, murder parties, lotos, bingos, quiz, enquêtes ;
            - marchés, salons, foires, brocantes, vide-greniers, villages de Noël ;
            - sport, randonnées, balades urbaines, activités nature, bénévolat, solidarité ;
            - bals, fest-noz, thés dansants, soirées salsa où le public danse (participatif);
            - billets de parcs ou de passes (Disney, Futuroscope, Parc Astérix, pass salon).

            INDICES :
            - La catégorie de la source pèse plus que le titre, le titre plus que la description.
            - « Science-fiction » est un genre de théâtre, pas de la science.
            - « Sortie de résidence », « restitution publique » : spectacle si des artistes jouent devant du public => une sortie de résidence est un spectacle vivant.
            - Un titre de spectacle connu (« Le Malade imaginaire », « Les Misérables ») avec un lieu de spectacle → garder.

            Format d'un résultat :
            {"id": 12, "decision": "garder", "confiance": 95, "justification": "…"}
            {"id": 13, "decision": "exclure", "confiance": 90, "justification": "…"}
            TXT;

        return [
            ['role' => 'system', 'content' => $systeme],
            ['role' => 'user', 'content' => json_encode(['annonces' => $elements->map(fn (ElementATraiter $e) => [
                'id' => $e->id,
                'source' => $e->cible?->nom,
                'titre' => $e->donnees['titre'] ?? '',
                'categories' => $e->donnees['categories_source'] ?? [],
                'description' => Str::limit(strip_tags((string) ($e->donnees['description'] ?? '')), 300),
                'lieu' => $e->donnees['lieu'] ?? null,
                'ville' => $e->donnees['ville'] ?? null,
            ])->values()->all()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];
    }

    protected function schemaResultat(): array
    {
        return [
            'type' => 'object',
            'properties' => ['decision' => ['type' => 'string', 'enum' => ['garder', 'exclure']]],
            'required' => ['decision'],
        ];
    }

    public function lireResultat(array $resultat, ElementATraiter $element): ?array
    {
        return in_array($resultat['decision'] ?? null, ['garder', 'exclure'], true) ? ['decision' => $resultat['decision']] : null;
    }

    public function gardeFou(ElementATraiter $element, array $proposition): ?string
    {
        $titre = ' '.Texte::normaliser((string) ($element->donnees['titre'] ?? '')).' ';
        [$mots, $raison] = $proposition['decision'] === 'garder'
            ? [self::MOTS_A_VERIFIER_SI_GARDE, 'gardée alors que le titre contient « %s »']
            : [self::MOTS_A_VERIFIER_SI_EXCLU, 'exclue alors que le titre contient « %s »'];

        foreach ($mots as $mot) {
            if (str_contains($titre, " {$mot} ")) {
                return sprintf($raison, $mot);
            }
        }

        return null;
    }

    public function appliquer(PropositionIa $proposition): void
    {
        app(DeciderTri::class)->handle($proposition->element->newCollection([$proposition->element]),
            $proposition->proposition['decision'] === 'garder' ? IssueFiltrage::Garde : IssueFiltrage::Exclu);
    }

    public function libelle(PropositionIa $proposition): string
    {
        return ($proposition->proposition['decision'] ?? null) === 'garder' ? 'Garder (publier)' : 'Exclure';
    }

    public function concorde(PropositionIa $proposition, ElementATraiter $element): ?bool
    {
        $issue = $element->decision['issue'] ?? null;

        if (! in_array($issue, [IssueFiltrage::Garde->value, IssueFiltrage::Exclu->value], true) || ! empty($element->decision['automatique'])) {
            return null;
        }

        return ($proposition->proposition['decision'] === 'garder') === ($issue === IssueFiltrage::Garde->value);
    }
}
