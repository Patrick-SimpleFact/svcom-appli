<?php

namespace App\Ia\Taches;

use App\Actions\EnregistrerCorrespondanceGenre;
use App\Actions\IgnorerCategorie;
use App\Enums\FileATraiter;
use App\Models\CorrespondanceGenre;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Offre;
use App\Models\PropositionIa;
use App\Models\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * File « À classer » : pour une catégorie d'une source, l'assistant propose un genre (et « Jeune public »),
 * ou de l'ignorer si elle est trop large : une correspondance passe avant les mots du titre (ClasseurGenres)
 * et rangerait toutes ses annonces dans un seul genre.
 */
class ClasserCategories extends TacheIa
{
    public const EXEMPLES_MAX = 8;

    public const CORRESPONDANCES_MAX = 40;

    /** Ce que recouvre chaque genre, pour le modèle. */
    public const AIDE_GENRES = [
        'theatre' => 'pièces, comédies, théâtre classique ou contemporain, seul-en-scène théâtral',
        'humour' => 'one-man/one-woman show, stand-up, humoristes, café-théâtre comique',
        'concert' => 'musique en public, tous styles (chanson, rock, rap, jazz, classique, électro…)',
        'comedie-musicale-cabaret' => 'comédies musicales, cabaret, revues, music-hall',
        'danse' => 'ballet, danse contemporaine, hip-hop, flamenco…',
        'cirque-magie' => 'cirque, acrobatie, magie, illusion, mentalisme, hypnose',
        'opera-lyrique' => 'opéra, opérette, récital lyrique',
        'autres' => 'spectacles vivants hors de ces genres (marionnettes, arts de la rue, conte…)',
    ];

    public function file(): FileATraiter
    {
        return FileATraiter::AClasser;
    }

    public function cleMode(): string
    {
        return 'ia_mode_a_classer';
    }

    protected function filtrer(Builder $requete): Builder
    {
        return $requete->where('cible_type', (new Source)->getMorphClass());
    }

    public function messages(Collection $elements): array
    {
        $genres = Genre::orderBy('ordre')->get()
            ->map(fn (Genre $g) => "- {$g->slug} : {$g->libelle} (".(self::AIDE_GENRES[$g->slug] ?? '').')')
            ->implode("\n");

        $systeme = <<<TXT
            Tu aides à ranger les annonces de spectacles vivants d'une application française.
            Chaque billetterie ou agenda (la « source ») range ses annonces dans ses propres catégories. On cherche, pour une catégorie d'une source, le genre de l'application qui lui correspond.
            Attention : le genre choisi s'appliquera à TOUTES les annonces de cette catégorie, avant toute autre règle.
            Pour chaque catégorie, réponds :
            - "classer" si la catégorie, à elle seule, désigne un seul genre pour la quasi-totalité de ses annonces ; donne alors "genre" (un des codes ci-dessous) et "jeune_public" (true seulement si la catégorie vise les enfants ou les familles) ;
            - "ignorer" si la catégorie est trop large ou mélange plusieurs genres (ex. « Spectacles », « Arts & Theatre », « ShowEvent », « Concerts, Festivals, Musical ») ; "genre" vaut alors null.
            Les exemples de titres aident à comprendre la catégorie, mais c'est la catégorie elle-même qui doit décider.
            Genres possibles (code : libellé) :
            {$genres}
            Format exact d'un résultat : {"id": 12, "decision": "classer", "genre": "humour", "jeune_public": false, "confiance": 90, "justification": "…"}
            ou {"id": 13, "decision": "ignorer", "genre": null, "jeune_public": false, "confiance": 85, "justification": "…"}.
            {$this->consigneReponse()}
            TXT;

        $donnees = [
            'deja_classees' => $this->correspondancesConnues(),
            'categories' => $elements->map(fn (ElementATraiter $e) => [
                'id' => $e->id,
                'source' => $e->cible?->nom,
                'categorie' => $e->donnees['categorie'],
                'nb_annonces' => $e->donnees['nb_annonces'] ?? null,
                'exemples_de_titres' => $this->exemples($e),
            ])->values()->all(),
        ];

        return [
            ['role' => 'system', 'content' => $systeme],
            ['role' => 'user', 'content' => json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];
    }

    protected function schemaResultat(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => ['classer', 'ignorer']],
                'genre' => ['type' => ['string', 'null'], 'enum' => [...Genre::pluck('slug')->all(), null]],
                'jeune_public' => ['type' => 'boolean'],
            ],
            'required' => ['decision', 'genre', 'jeune_public'],
        ];
    }

    public function lireResultat(array $resultat, ElementATraiter $element): ?array
    {
        return match ($resultat['decision'] ?? null) {
            'ignorer' => ['decision' => 'ignorer'],
            'classer' => Genre::where('slug', $resultat['genre'] ?? '')->exists()
                ? ['decision' => 'classer', 'genre' => $resultat['genre'], 'jeune_public' => (bool) ($resultat['jeune_public'] ?? false)]
                : null,
            default => null,
        };
    }

    public function appliquer(PropositionIa $proposition): void
    {
        $element = $proposition->element;

        if ($proposition->proposition['decision'] === 'ignorer') {
            app(IgnorerCategorie::class)->handle($element);

            return;
        }

        app(EnregistrerCorrespondanceGenre::class)->handle(
            $element->cible,
            $element->donnees['categorie'],
            Genre::where('slug', $proposition->proposition['genre'])->firstOrFail(),
            (bool) $proposition->proposition['jeune_public'],
        );
    }

    public function libelle(PropositionIa $proposition): string
    {
        $p = $proposition->proposition;

        if (($p['decision'] ?? null) === 'ignorer') {
            return 'Ignorer (trop large)';
        }

        return (Genre::firstWhere('slug', $p['genre'] ?? '')?->libelle ?? '?').(! empty($p['jeune_public']) ? ' · Jeune public' : '');
    }

    public function concorde(PropositionIa $proposition, ElementATraiter $element): ?bool
    {
        $decision = $element->decision ?? [];
        $p = $proposition->proposition;

        return match (true) {
            ! empty($decision[IgnorerCategorie::DECISION]) => $p['decision'] === 'ignorer',
            isset($decision['genre']) => ($p['decision'] ?? null) === 'classer' && $p['genre'] === $decision['genre'] && (bool) $p['jeune_public'] === (bool) ($decision['jeune_public'] ?? false),
            default => null,
        };
    }

    /** Titres d'annonces encore au programme dans cette catégorie, à défaut ceux gardés dans l'élément. @return list<string> */
    private function exemples(ElementATraiter $element): array
    {
        $titres = Offre::where('source_id', $element->cible_id)
            ->whereNull('disparue_le')
            ->whereJsonContains('donnees_normalisees->categories_source', $element->donnees['categorie'])
            ->latest('id')
            ->limit(self::EXEMPLES_MAX * 3)
            ->pluck('donnees_normalisees')
            ->map(fn ($d) => is_array($d) ? ($d['titre'] ?? null) : null);

        return collect($element->donnees['exemples'] ?? [])->merge($titres)
            ->filter()->map(fn (string $t) => Str::limit($t, 100))->unique()->take(self::EXEMPLES_MAX)->values()->all();
    }

    /** Correspondances déjà décidées par Patrick, comme repères. @return list<string> */
    private function correspondancesConnues(): array
    {
        return CorrespondanceGenre::with(['genre', 'source'])->latest('id')->limit(self::CORRESPONDANCES_MAX)->get()
            ->map(fn (CorrespondanceGenre $c) => "{$c->source?->nom} · « {$c->categorie_source} » → {$c->genre?->slug}".($c->jeune_public ? ' (jeune public)' : ''))
            ->all();
    }
}
