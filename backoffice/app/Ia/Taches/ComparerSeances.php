<?php

namespace App\Ia\Taches;

use App\Actions\DeciderDoublon;
use App\Actions\DedoublonnerOffre;
use App\Enums\TypeDecisionDedoublonnage;
use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\PropositionIa;
use App\Support\Texte;
use Illuminate\Support\Collection;

/**
 * Deux séances de billetteries différentes à comparer (files « Doublons probables » et « Fusions à contrôler »).
 * La décision passe par DeciderDoublon : mémorisée, réappliquée à chaque collecte, une séparation n'est jamais défaite.
 * Garde-fous repris du traitement du stock (nuit du 21/10/2026), vérifiés même quand les modèles sont sûrs.
 */
abstract class ComparerSeances extends TacheIa
{
    /** Mots trop courants pour dire que deux titres désignent le même spectacle. */
    public const MOTS_COURANTS = ['le', 'la', 'les', 'l', 'de', 'du', 'des', 'd', 'un', 'une', 'et', 'en', 'a', 'au', 'aux', 'dans', 'par', 'pour',
        'avec', 'sur', 'the', 'of', 'and', 'comedy', 'club', 'comedie', 'theatre', 'spectacle', 'show', 'tournee', 'tour', 'concert', 'live',
        'nouveau', 'paris', 'lyon', 'salle', 'scene', 'grande', 'petite', 'saison', 'edition', 'presente', 'ballet', 'orchestre'];

    /** Écart d'heure en dessous duquel deux séances au même lieu, aux titres proches, sont presque sûrement la même. */
    public const MEME_HEURE_MINUTES = 5;

    /** @return array{0: string, 1: string} les deux réponses possibles : [même séance, séances différentes] */
    abstract protected function choix(): array;

    abstract protected function tache(): string;

    public function messages(Collection $elements): array
    {
        $systeme = $this->socle()."\n\n".$this->tache();

        return [
            ['role' => 'system', 'content' => $systeme],
            ['role' => 'user', 'content' => json_encode(['paires' => $elements->map(fn (ElementATraiter $e) => [
                'id' => $e->id,
                'ecart_minutes' => $e->donnees['ecart_minutes'] ?? null,
                'ressemblance_titres' => $e->donnees['ressemblance'] ?? null,
                'seance_a' => self::seance(Offre::find($e->donnees['offre_a_id'] ?? 0)),
                'seance_b' => self::seance(Offre::find($e->donnees['offre_b_id'] ?? 0)),
            ])->values()->all()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function seance(?Offre $offre): ?array
    {
        if ($offre === null) {
            return null;
        }
        $d = $offre->donnees_normalisees ?? [];

        return [
            'source' => $offre->source?->nom,
            'titre' => $d['titre'] ?? null,
            'debut' => $offre->debut?->setTimezone('Europe/Paris')->format('Y-m-d H:i'),
            'lieu' => $offre->lieu?->nom,
            'ville' => $offre->lieu?->ville?->nom,
            'prix' => [$d['prix_min'] ?? null, $d['prix_max'] ?? null],
            'categories' => $d['categories_source'] ?? [],
            'artistes' => $d['artistes'] ?? null,
        ];
    }

    protected function schemaResultat(): array
    {
        return [
            'type' => 'object',
            'properties' => ['decision' => ['type' => 'string', 'enum' => $this->choix()]],
            'required' => ['decision'],
        ];
    }

    public function lireResultat(array $resultat, ElementATraiter $element): ?array
    {
        return in_array($resultat['decision'] ?? null, $this->choix(), true) ? ['decision' => $resultat['decision']] : null;
    }

    protected function fusionner(array $proposition): bool
    {
        return $proposition['decision'] === $this->choix()[0];
    }

    public function gardeFou(ElementATraiter $element, array $proposition): ?string
    {
        [$a, $b] = [Offre::find($element->donnees['offre_a_id'] ?? 0), Offre::find($element->donnees['offre_b_id'] ?? 0)];
        if ($a === null || $b === null) {
            return 'une des deux séances a disparu';
        }

        $ecart = $a->debut && $b->debut ? abs($a->debut->diffInMinutes($b->debut)) : null;
        $memeLieu = $a->lieu_id !== null && in_array($b->lieu_id, app(DedoublonnerOffre::class)->lieuxProches($a->lieu_id), true);
        $communs = self::motsCommuns($a, $b);

        if ($this->fusionner($proposition)) {
            $dedoublonnage = app(DedoublonnerOffre::class);

            return match (true) {
                $ecart > 0 && ($dedoublonnage->seancesEnchainees($a) || $dedoublonnage->seancesEnchainees($b)) => 'séances enchaînées : une billetterie joue ce spectacle plusieurs fois en moins d’une heure dans ce lieu',
                $ecart > 0 && $this->conflit($a, $b) !== null => 'la billetterie '.$this->conflit($a, $b).' vend elle-même les deux horaires',
                ! $memeLieu => 'les deux séances ne sont pas dans le même lieu',
                $communs < ($ecart !== null && $ecart < self::MEME_HEURE_MINUTES ? 0.6 : 0.8) => 'les titres ont trop peu de mots en commun',
                default => null,
            };
        }

        return $ecart !== null && $ecart < self::MEME_HEURE_MINUTES && $memeLieu && $communs >= 0.6
            ? 'même lieu, même heure et titres proches : sans doute la même séance'
            : null;
    }

    /** Une billetterie vend-elle le même spectacle aux deux heures ? Alors ce sont deux séances. Renvoie son nom. */
    private function conflit(Offre $a, Offre $b): ?string
    {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($x->titre_comparable !== null && Offre::where('source_id', $x->source_id)->where('lieu_id', $x->lieu_id)
                ->where('titre_comparable', $x->titre_comparable)->whereNull('disparue_le')->where('debut', $y->debut)->exists()) {
                return $x->source?->nom;
            }
        }

        return null;
    }

    /** Part des mots distinctifs du titre le plus court qu'on retrouve dans l'autre (0 à 1). */
    public static function motsCommuns(Offre $a, Offre $b): float
    {
        $mots = fn (Offre $o): array => array_values(array_unique(array_filter(explode(' ', Texte::normaliser((string) ($o->donnees_normalisees['titre'] ?? ''))),
            fn (string $m) => mb_strlen($m) > 1 && ! ctype_digit($m) && ! in_array($m, self::MOTS_COURANTS, true))));
        [$x, $y] = [$mots($a), $mots($b)];

        return $x === [] || $y === [] ? 0.0 : count(array_intersect($x, $y)) / min(count($x), count($y));
    }

    public function appliquer(PropositionIa $proposition): void
    {
        $element = $proposition->element;
        app(DeciderDoublon::class)->handle(
            Offre::findOrFail($element->donnees['offre_a_id']),
            Offre::findOrFail($element->donnees['offre_b_id']),
            $this->fusionner($proposition->proposition) ? TypeDecisionDedoublonnage::Fusionner : TypeDecisionDedoublonnage::Separer,
        );
    }

    public function concorde(PropositionIa $proposition, ElementATraiter $element): ?bool
    {
        $type = $element->decision['type'] ?? null;

        return in_array($type, ['fusionner', 'separer'], true) ? $this->fusionner($proposition->proposition) === ($type === 'fusionner') : null;
    }
}
