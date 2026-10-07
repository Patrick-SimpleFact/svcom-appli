<?php

namespace App\Api;

use App\Enums\PrecisionPosition;
use App\Enums\TypeLienSource;
use App\Enums\TypeRepresentation;
use App\Exceptions\ErreurApi;
use App\Models\Artiste;
use App\Models\ClicSortant;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Support\Point;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fiches (F5, F4.2, API §5) : la fiche d'un spectacle avec les séances du jour et leurs billetteries triées (F5.4),
 * ses autres dates par lieu (F5.3), les pages lieu et artiste.
 */
class Fiches
{
    private ?string $empreinte = null;

    public function __construct(private Recherche $recherche) {}

    /** F5.2 : la fiche, positionnée sur la séance demandée si elle est à venir, sinon sur la prochaine (la plus proche si on a une position). */
    public function spectacle(int $id, ?int $representationId = null, ?Point $position = null, ?string $appareil = null): array
    {
        $this->empreinte = $appareil ? ClicSortant::empreinte($appareil) : null;

        $spectacle = Spectacle::with(['genre', 'festival', 'artistes'])->where('masque', false)->find($id) ?? throw new ErreurApi('introuvable', 'Spectacle introuvable.', 404);
        $aVenir = $this->aVenir()->where('representations.spectacle_id', $id);

        $choisie = $representationId ? (clone $aVenir)->where('representations.id', $representationId)->first() : null;
        $choisie ??= $position
            ? (clone $aVenir)->orderByRaw('ST_Distance(representations.position, ?::geography)', [$position->versEwkt()])->orderBy('representations.date_locale')->first()
            : (clone $aVenir)->orderBy('representations.date_locale')->orderBy('representations.debut')->first();

        $seances = $choisie
            ? (clone $aVenir)->where('representations.lieu_id', $choisie->lieu_id)->where('representations.date_locale', $choisie->date_locale)->orderBy('representations.debut')->get()
            : collect();
        $lieu = $choisie ? Lieu::with('ville')->find($choisie->lieu_id) : null;
        $offres = $this->offres($seances->pluck('id')->all());

        return [
            'id' => $spectacle->id,
            'titre' => $spectacle->titre,
            'image_url' => $spectacle->image_url,
            'genre' => $spectacle->genre ? ['id' => $spectacle->genre->id, 'slug' => $spectacle->genre->slug, 'libelle' => $spectacle->genre->libelle] : null,
            'classification' => $spectacle->classification_fine,
            'jeune_public' => $spectacle->jeune_public,
            'age_min' => $spectacle->age_min,
            'duree_minutes' => $spectacle->duree_minutes,
            'festival' => $spectacle->festival?->nom,
            'description' => self::nettoyer($spectacle->description),
            'artistes' => $spectacle->artistes->map(fn (Artiste $a) => ['id' => $a->id, 'nom' => $a->nom, 'role' => $a->pivot->role])->values(),
            // Plus de date à venir : « Ce spectacle est terminé » (F5.4).
            'termine' => $choisie === null,
            'lieu' => $lieu ? self::decrireLieu($lieu, $position) : null,
            'jour' => $choisie?->date_locale?->toDateString(),
            'seances' => $seances->map(fn (Representation $r) => $this->seance($r, $lieu, $offres->get($r->id, collect())))->values(),
            'prix' => self::prix($seances),
            'autres_dates' => max(0, $this->aVenir()->where('representations.spectacle_id', $id)->count() - $seances->count()),
            'sources' => $offres->flatten()->map(fn (Offre $o) => $o->source->nom)->unique()->values(),
            'mentions' => $offres->flatten()->map(fn (Offre $o) => $o->source->mention_obligatoire)->filter()->unique()->values(),
            'mis_a_jour_le' => $offres->flatten()->max('vue_le')?->toIso8601String(),
        ];
    }

    /** F5.3 : toutes les dates à venir, regroupées par lieu, le plus proche d'abord (sinon la date la plus proche d'abord). */
    public function autresDates(int $id, ?Point $position = null): array
    {
        Spectacle::where('masque', false)->find($id) ?? throw new ErreurApi('introuvable', 'Spectacle introuvable.', 404);

        $representations = $this->aVenir()->where('representations.spectacle_id', $id)
            ->with('lieu.ville')
            ->when($position, fn (Builder $q) => $q->selectRaw('ST_Distance(representations.position, ?::geography) as distance_m', [$position->versEwkt()]))
            ->orderBy('representations.date_locale')->orderBy('representations.debut')
            ->get();

        $lieux = $representations->groupBy('lieu_id')->map(fn (Collection $dates) => [
            'lieu' => self::decrireLieu($dates->first()->lieu, $position),
            'dates' => $dates->count(),
            'representations' => $dates->map(fn (Representation $r) => [
                'representation_id' => $r->id,
                'date_locale' => $r->date_locale->toDateString(),
                'date_fin' => $r->date_fin?->toDateString(),
                'type' => $r->type->value,
                'debut' => $r->debut?->setTimezone($r->lieu->fuseau_horaire ?? 'Europe/Paris')->toIso8601String(),
                'complet' => $r->complet,
            ])->values(),
        ]);

        $lieux = $position
            ? $lieux->sortBy(fn (array $l) => $l['lieu']['distance_m'])
            : $lieux->sortBy(fn (array $l) => $l['representations'][0]['date_locale']);

        return ['lieux' => $lieux->values()];
    }

    /** F4.2 : la page d'un lieu, et ses spectacles à venir groupés par jour. */
    public function lieu(int $id, ?Point $position = null, int $page = 1): array
    {
        $lieu = Lieu::with('ville')->find($id) ?? throw new ErreurApi('introuvable', 'Lieu introuvable.', 404);
        $lieu = $lieu->fusionne_dans_id ? Lieu::with('ville')->findOrFail($lieu->fusionne_dans_id) : $lieu; // doublon fusionné : le lieu conservé

        if ($lieu->masque) {
            throw new ErreurApi('introuvable', 'Lieu introuvable.', 404);
        }

        $dates = $this->recherche->handle(['lieu_id' => $lieu->id, 'centre' => $position, 'page' => $page, 'journaliser' => false]);

        return [...self::decrireLieu($lieu, $position), 'suivi' => null, ...collect($dates)->only(['total', 'total_plus', 'jours', 'suivant'])->all()];
    }

    /** F4.2 : la page d'un artiste, et ses dates à venir (les plus proches d'abord si on a une position). */
    public function artiste(int $id, ?Point $position = null, int $page = 1): array
    {
        $artiste = Artiste::find($id) ?? throw new ErreurApi('introuvable', 'Artiste introuvable.', 404);
        $artiste = $artiste->fusionne_dans_id ? Artiste::findOrFail($artiste->fusionne_dans_id) : $artiste;

        $dates = $this->recherche->handle([
            'spectacle_ids' => $artiste->spectacles()->pluck('spectacles.id')->all(),
            'centre' => $position, 'tri' => $position ? 'distance' : 'date', 'page' => $page, 'journaliser' => false,
        ]);

        return [
            'id' => $artiste->id, 'nom' => $artiste->nom, 'type' => $artiste->type->value, 'image_url' => $artiste->image_url, 'suivi' => null,
            ...collect($dates)->only(['total', 'total_plus', 'jours', 'suivant'])->all(),
        ];
    }

    /** Représentations visibles à venir (une séance commencée depuis moins de 15 min compte encore). */
    private function aVenir(): Builder
    {
        return Representation::query()->visibles()->select('representations.*')
            ->where(fn (Builder $q) => $q
                ->where(fn ($s) => $s->where('representations.type', TypeRepresentation::Seance->value)->where('representations.debut', '>=', now()->subMinutes(Fenetre::MINUTES_APRES_DEBUT)))
                ->orWhere(fn ($j) => $j->whereNull('representations.debut')->whereRaw('coalesce(representations.date_fin, representations.date_locale) >= ?', [today()->toDateString()])));
    }

    /** @return Collection<int, Collection<int, Offre>> offres encore en vente par une source active non masquée, par représentation */
    private function offres(array $representationIds): Collection
    {
        return Offre::with('source')
            ->whereIn('representation_id', $representationIds)
            ->whereNull('disparue_le')
            ->whereNotNull('lien')
            ->whereHas('source', fn ($q) => $q->where('actif', true)->where('masquee', false))
            ->get()
            ->groupBy('representation_id');
    }

    /**
     * Une séance et ses billetteries (F5.4) : celles qui vendent des billets d'abord, puis, à chaque fois,
     * places disponibles → prix le plus bas → affiliation (celle qui nous rémunère, à prix égal seulement).
     */
    private function seance(Representation $r, ?Lieu $lieu, Collection $offres): array
    {
        $triees = $offres->sortBy([
            fn (Offre $a, Offre $b) => $b->source->billetterie <=> $a->source->billetterie,
            fn (Offre $a, Offre $b) => $a->complet <=> $b->complet,
            fn (Offre $a, Offre $b) => ($a->prix_min ?? PHP_INT_MAX) <=> ($b->prix_min ?? PHP_INT_MAX),
            fn (Offre $a, Offre $b) => ($b->source->type_lien === TypeLienSource::Affilie) <=> ($a->source->type_lien === TypeLienSource::Affilie),
            fn (Offre $a, Offre $b) => $a->source->nom <=> $b->source->nom,
        ])->values();

        return [
            'representation_id' => $r->id,
            'type' => $r->type->value,
            'debut' => $r->debut?->setTimezone($lieu?->fuseau_horaire ?? 'Europe/Paris')->toIso8601String(),
            'date_fin' => $r->date_fin?->toDateString(),
            'salle' => $r->salle,
            'complet' => $r->complet,
            'billetteries' => $triees->map(fn (Offre $o, int $i) => [
                'offre_id' => $o->id,
                'source' => $o->source->nom,
                // false : lien vers l'organisateur (« Voir sur le site de l'organisateur »).
                'billetterie' => $o->source->billetterie,
                'prix_min' => $o->prix_min === null ? null : (float) $o->prix_min,
                'prix_max' => $o->prix_max === null ? null : (float) $o->prix_max,
                'complet' => $o->complet,
                'recommandee' => $i === 0,
                // L'empreinte de l'appareil voyage dans le lien (le navigateur intégré n'envoie pas X-Appareil) ; l'app ajoute origine et bouton.
                'lien_sortie' => url('/sortie/'.$o->id).($this->empreinte ? '?a='.$this->empreinte : ''),
            ])->all(),
        ];
    }

    /** F5.2 : « De 12 € à 25 € », « Gratuit », ou inconnu (« Tarifs sur la billetterie ») ; jamais estimé. */
    private static function prix(Collection $seances): array
    {
        return [
            'min' => $seances->min('prix_min') === null ? null : (float) $seances->min('prix_min'),
            'max' => $seances->max('prix_max') === null ? null : (float) $seances->max('prix_max'),
            'gratuit' => $seances->isNotEmpty() && $seances->every(fn (Representation $r) => $r->gratuit),
        ];
    }

    private static function decrireLieu(Lieu $lieu, ?Point $position): array
    {
        return [
            'id' => $lieu->id,
            'nom' => $lieu->nom,
            'adresse' => $lieu->adresse,
            'code_postal' => $lieu->code_postal,
            'ville' => $lieu->ville?->nom,
            'lat' => $lieu->position?->latitude,
            'lon' => $lieu->position?->longitude,
            'position_approximative' => $lieu->precision_position === PrecisionPosition::Commune,
            'telephone' => $lieu->telephone,
            'site_web' => $lieu->site_web,
            'distance_m' => $position && $lieu->position ? (int) (round(self::distanceMetres($position, $lieu->position) / 10) * 10) : null,
        ];
    }

    /** Distance à vol d'oiseau (formule de haversine), suffisante pour afficher « à 900 m ». */
    private static function distanceMetres(Point $a, Point $b): float
    {
        $rad = M_PI / 180;
        $h = sin(($b->latitude - $a->latitude) * $rad / 2) ** 2
            + cos($a->latitude * $rad) * cos($b->latitude * $rad) * sin(($b->longitude - $a->longitude) * $rad / 2) ** 2;

        return 2 * 6_371_000 * asin(min(1, sqrt($h)));
    }

    /**
     * Description lisible (F5.2) : balises HTML retirées (les vraies seulement : « < Post-punk – Paris > » reste), entités décodées,
     * paragraphes gardés. Vide → null (l'app affiche « Aucune description n'a été fournie »).
     */
    public static function nettoyer(?string $texte): ?string
    {
        if ($texte === null) {
            return null;
        }

        $texte = preg_replace('#<\s*(/p|/div)\s*>#i', "\n\n", $texte); // fin de paragraphe : ligne vide
        $texte = preg_replace('#<\s*(br|/li)\s*/?>#i', "\n", $texte);
        $texte = preg_replace('#</?[a-z][a-z0-9]*(\s[^<>]*)?/?>#i', '', $texte);
        $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texte = preg_replace("/[ \t\u{00A0}]+/u", ' ', $texte);
        $texte = trim(preg_replace("/\s*\n\s*(\n\s*)+/", "\n\n", $texte));

        return $texte === '' ? null : $texte;
    }
}
