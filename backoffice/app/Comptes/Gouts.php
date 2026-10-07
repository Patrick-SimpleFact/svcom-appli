<?php

namespace App\Comptes;

use App\Actions\PrioriserBoiteDeTravail;
use App\Enums\StatutRepresentation;
use App\Exceptions\ErreurApi;
use App\Models\Artiste;
use App\Models\Favori;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Nouveaute;
use App\Models\Parametre;
use App\Models\Preference;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Models\Suivi;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Point;
use Illuminate\Support\Facades\DB;

/**
 * Préférences, favoris, suivis et nouveautés d'un compte (F3, API §8).
 */
class Gouts
{
    public function preferences(Utilisateur $u): array
    {
        $p = $u->preferences()->with('zoneAlertesVille')->first() ?? new Preference(['genres' => []]);

        return [
            'genres' => $p->genres ?? [],
            'rayon_m' => $p->rayon_m,
            'zone_alertes' => [
                'ville' => $p->zoneAlertesVille ? ['id' => $p->zoneAlertesVille->id, 'nom' => $p->zoneAlertesVille->nom] : null,
                'rayon_km' => $p->zone_alertes_rayon_km ?? (int) Parametre::valeur('zone_alertes_rayon_km'),
            ],
            'alertes_actives' => $p->alertes_actives ?? true,
            'rappel_jour_j' => $p->rappel_jour_j ?? true,
        ];
    }

    /** Zone des alertes : une ville choisie, ou la commune la plus proche de la position habituelle (jamais la position elle-même, F2.11). */
    public function modifierPreferences(Utilisateur $u, array $d): array
    {
        $valeurs = collect($d)->only(['rayon_m', 'alertes_actives', 'rappel_jour_j'])->all();

        if (array_key_exists('genres', $d)) {
            $valeurs['genres'] = Genre::whereIn('id', $d['genres'] ?? [])->orderBy('ordre')->pluck('id')->all();
        }

        if (isset($d['zone_alertes']['lat'])) {
            $centre = new Point((float) $d['zone_alertes']['lat'], (float) $d['zone_alertes']['lon']);
            $valeurs['zone_alertes_ville_id'] = Ville::orderByRaw('position <-> ?::geography', [$centre->versEwkt()])->value('id');
        } elseif (array_key_exists('ville_id', $d['zone_alertes'] ?? [])) {
            $valeurs['zone_alertes_ville_id'] = $d['zone_alertes']['ville_id'];
        }

        if (isset($d['zone_alertes']['rayon_km'])) {
            $valeurs['zone_alertes_rayon_km'] = (int) $d['zone_alertes']['rayon_km'];
        }

        $u->preferences()->updateOrCreate([], $valeurs);

        return $this->preferences($u);
    }

    /** Favoris groupés : ce soir, à venir, passés (sur la séance choisie, sinon la prochaine du spectacle). */
    public function favoris(Utilisateur $u): array
    {
        $aujourdhui = PrioriserBoiteDeTravail::aujourdhui()->toDateString();
        $groupes = ['ce_soir' => [], 'a_venir' => [], 'passes' => []];

        foreach ($u->favoris()->with(['spectacle', 'representation.lieu.ville'])->latest()->get() as $f) {
            $r = $f->representation && $f->representation->statut === StatutRepresentation::Programmee ? $f->representation : $this->prochaine($f->spectacle_id);
            $jour = $r?->date_locale?->toDateString();
            $fin = $r?->date_fin?->toDateString() ?? $jour;
            $groupe = match (true) {
                $r === null || $fin < $aujourdhui => 'passes',
                $jour <= $aujourdhui => 'ce_soir',
                default => 'a_venir',
            };

            $groupes[$groupe][] = [
                'id' => $f->id,
                'spectacle' => ['id' => $f->spectacle->id, 'titre' => $f->spectacle->titre, 'image_url' => $f->spectacle->image_url],
                'representation' => $r ? [
                    'id' => $r->id, 'date_locale' => $jour, 'type' => $r->type->value, 'complet' => $r->complet,
                    'debut' => $r->debut?->setTimezone($r->lieu?->fuseau_horaire ?? 'Europe/Paris')->toIso8601String(),
                    'lieu' => ['id' => $r->lieu?->id, 'nom' => $r->lieu?->nom, 'ville' => $r->lieu?->ville?->nom],
                ] : null,
                'seance_choisie' => $f->representation_id !== null,
            ];
        }

        $groupes['a_venir'] = collect($groupes['a_venir'])->sortBy('representation.date_locale')->values()->all();

        return $groupes;
    }

    public function ajouterFavori(Utilisateur $u, int $spectacleId, ?int $representationId): Favori
    {
        $spectacle = Spectacle::where('masque', false)->find($spectacleId) ?? throw new ErreurApi('introuvable', 'Spectacle introuvable.', 404);

        if ($representationId !== null && ! Representation::where('id', $representationId)->where('spectacle_id', $spectacle->id)->exists()) {
            throw new ErreurApi('seance_invalide', 'Cette séance n’est pas celle de ce spectacle.', 422);
        }

        return Favori::updateOrCreate(['utilisateur_id' => $u->id, 'spectacle_id' => $spectacle->id], ['representation_id' => $representationId]);
    }

    /** Lieux et artistes suivis, avec le nombre de nouveautés pas encore vues. */
    public function suivis(Utilisateur $u): array
    {
        $suivis = $u->suivis()->latest()->get();
        $nonVues = $u->nouveautes()->whereNull('vue_le')->whereNotNull('suivi_id')->selectRaw('suivi_id, count(*) as n')->groupBy('suivi_id')->pluck('n', 'suivi_id');
        $lieux = Lieu::with('ville')->whereIn('id', $suivis->where('type', 'lieu')->pluck('cible_id'))->get()->keyBy('id');
        $artistes = Artiste::whereIn('id', $suivis->where('type', 'artiste')->pluck('cible_id'))->get()->keyBy('id');

        return $suivis->map(fn (Suivi $s) => [
            'id' => $s->id,
            'type' => $s->type,
            'cible' => $s->type === 'lieu'
                ? ['id' => $s->cible_id, 'nom' => $lieux[$s->cible_id]?->nom, 'ville' => $lieux[$s->cible_id]?->ville?->nom]
                : ['id' => $s->cible_id, 'nom' => $artistes[$s->cible_id]?->nom],
            'nouveautes' => (int) ($nonVues[$s->id] ?? 0),
        ])->values()->all();
    }

    /** Suivre un lieu ou un artiste : une seule fois (unicité, F3) ; un doublon fusionné est remplacé par le lieu ou l'artiste conservé. */
    public function suivre(Utilisateur $u, string $type, int $id): array
    {
        $cible = $type === 'lieu' ? Lieu::find($id) : Artiste::find($id);
        $cible ?? throw new ErreurApi('introuvable', $type === 'lieu' ? 'Lieu introuvable.' : 'Artiste introuvable.', 404);
        $cibleId = $cible->fusionne_dans_id ?? $cible->id;

        $suivi = Suivi::firstOrCreate(['utilisateur_id' => $u->id, 'type' => $type, 'cible_id' => $cibleId]);

        return ['suivi' => collect($this->suivis($u))->firstWhere('id', $suivi->id), 'deja_suivi' => ! $suivi->wasRecentlyCreated];
    }

    /** Nouveautés pas encore vues (badge de l'onglet Favoris), puis celles vues depuis moins de 30 jours. */
    public function nouveautes(Utilisateur $u): array
    {
        $liste = $u->nouveautes()->with(['spectacle', 'representation.lieu.ville'])
            ->where(fn ($q) => $q->whereNull('vue_le')->orWhere('vue_le', '>=', now()->subDays(30)))
            ->orderByRaw('vue_le is not null')->latest('cree_le')->limit(100)->get();

        return [
            'non_vues' => $liste->whereNull('vue_le')->count(),
            'nouveautes' => $liste->map(fn (Nouveaute $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'vue' => $n->vue_le !== null,
                'cree_le' => $n->cree_le->toIso8601String(),
                'spectacle' => $n->spectacle ? ['id' => $n->spectacle->id, 'titre' => $n->spectacle->titre] : null,
                'representation' => $n->representation ? [
                    'id' => $n->representation->id, 'date_locale' => $n->representation->date_locale->toDateString(),
                    'debut' => $n->representation->debut?->setTimezone($n->representation->lieu?->fuseau_horaire ?? 'Europe/Paris')->toIso8601String(),
                    'lieu' => ['id' => $n->representation->lieu?->id, 'nom' => $n->representation->lieu?->nom, 'ville' => $n->representation->lieu?->ville?->nom],
                ] : null,
            ])->values()->all(),
        ];
    }

    public function marquerVues(Utilisateur $u, ?array $ids): int
    {
        return $u->nouveautes()->whereNull('vue_le')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->update(['vue_le' => now()]);
    }

    /**
     * « Pour vous » pour un compte (F3.3) : ses genres aimés, plus les spectacles de ses lieux et artistes suivis.
     *
     * @return array{genres: list<int>, lieux: list<int>, spectacles: list<int>}
     */
    public function pourVous(Utilisateur $u): array
    {
        $suivis = $u->suivis()->get();

        return [
            'genres' => $u->preferences?->genres ?? [],
            'lieux' => $suivis->where('type', 'lieu')->pluck('cible_id')->all(),
            'spectacles' => DB::table('spectacle_artiste')->whereIn('artiste_id', $suivis->where('type', 'artiste')->pluck('cible_id'))->pluck('spectacle_id')->unique()->values()->all(),
        ];
    }

    private function prochaine(int $spectacleId): ?Representation
    {
        return Representation::query()->visibles()->select('representations.*')->with('lieu.ville')
            ->where('representations.spectacle_id', $spectacleId)
            ->whereRaw('coalesce(representations.date_fin, representations.date_locale) >= ?', [PrioriserBoiteDeTravail::aujourdhui()->toDateString()])
            ->orderBy('representations.date_locale')->orderBy('representations.debut')->first();
    }
}
