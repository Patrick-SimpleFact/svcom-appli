<?php

namespace App\Ia;

use App\Actions\DeciderDoublon;
use App\Actions\DeciderTri;
use App\Actions\EnregistrerCorrespondanceGenre;
use App\Actions\IgnorerCategorie;
use App\Actions\SeparerSpectacle;
use App\Enums\FileATraiter;
use App\Enums\IssueFiltrage;
use App\Enums\ModeIa;
use App\Enums\StatutElement;
use App\Enums\TypeDecisionDedoublonnage;
use App\Filament\Pages\BoiteDeTravail;
use App\Filament\Support\Urgence;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Offre;
use App\Models\Spectacle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * « Mes actions du jour » (plan-module-ia v2) : ce qui attend vraiment Patrick, une carte par décision.
 * - Une file dont l'assistant est désactivé : tous ses éléments en attente.
 * - Une file confiée à l'assistant : seulement ce qu'il a examiné sans trancher (« à décider »), ou ce qu'il propose
 *   en observation ; le reste sera examiné à son prochain passage.
 * Chaque carte pose la question en clair, montre les faits utiles et l'avis de l'IA, et propose des boutons dont la
 * conséquence est écrite. Le clic passe par l'action habituelle de la file, au nom de l'admin connecté.
 */
class MesActions
{
    public function __construct(private Assistant $assistant) {}

    /** Les files ouvertes à l'admin connecté (modèle de profil). @return list<FileATraiter> */
    public function files(): array
    {
        return array_map(fn (string $f) => FileATraiter::from($f), array_keys(BoiteDeTravail::ressourcesOuvertes()));
    }

    public function requete(): Builder
    {
        $avecIa = collect($this->files())->filter(fn (FileATraiter $f) => isset(Assistant::TACHES[$f->value]) && $this->assistant->tache($f)->mode() !== ModeIa::Desactive);
        $sansIa = collect($this->files())->reject(fn (FileATraiter $f) => $avecIa->contains($f));

        return ElementATraiter::query()
            ->where('statut', StatutElement::EnAttente)
            ->where(fn (Builder $q) => $q
                ->whereIn('file', $sansIa->all())
                ->orWhere(fn (Builder $q) => $q->whereIn('file', $avecIa->all())->where(fn (Builder $q) => $q->has('douteIa')->orHas('propositionIa'))))
            ->with(['cible', 'propositionIa', 'douteIa']);
    }

    public function nombre(): int
    {
        return $this->requete()->count();
    }

    /** Les éléments en attente que l'assistant n'a pas encore examinés (ils ne sont pas encore dans « Mes actions »). */
    public function enExamen(): int
    {
        $avecIa = collect($this->files())->filter(fn (FileATraiter $f) => isset(Assistant::TACHES[$f->value]) && $this->assistant->tache($f)->mode() !== ModeIa::Desactive);

        return ElementATraiter::where('statut', StatutElement::EnAttente)->whereIn('file', $avecIa->all())
            ->doesntHave('douteIa')->doesntHave('propositionIa')->count();
    }

    /**
     * La prochaine carte, en sautant celles remises à plus tard.
     *
     * @param  list<int>  $passes
     */
    public function suivante(array $passes = []): ?ElementATraiter
    {
        return Urgence::trier($this->requete()->whereKeyNot($passes))->first();
    }

    /**
     * Ce qu'affiche la carte : question, faits, avis de l'IA, boutons (clé => [libellé, conséquence, couleur]).
     *
     * @return array{file: string, urgence: ?string, question: string, faits: list<array{0: string, 1: ?string, 2?: ?string}>, comparaison: ?array, lieux: ?array, avis: ?string, proposition: ?string, boutons: array<string, array{0: string, 1: string, 2: string}>, lien: ?string}
     */
    public function carte(ElementATraiter $element): array
    {
        $d = $element->donnees ?? [];
        $carte = [
            'file' => $element->file->getLabel(),
            'urgence' => $element->urgence >= 2 ? Urgence::LIBELLES[$element->urgence] : null,
            'faits' => [], 'comparaison' => null, 'lieux' => null, 'boutons' => [],
            'avis' => $element->douteIa?->justification,
            'proposition' => $element->propositionIa
                ? $this->assistant->tache($element->file)->libelle($element->propositionIa)." ({$element->propositionIa->confiance} %) — ".$element->propositionIa->justification
                : null,
            'lien' => isset(BoiteDeTravail::RESSOURCES[$element->file->value]) ? BoiteDeTravail::RESSOURCES[$element->file->value]::getUrl() : null,
        ];

        return match ($element->file) {
            FileATraiter::ATrier => [...$carte,
                'question' => 'Faut-il publier cette annonce dans l’agenda ?',
                'faits' => [
                    ['Titre', $d['titre'] ?? '', $d['lien'] ?? null],
                    ['Billetterie', $element->cible?->nom],
                    ['Catégories', implode(' › ', (array) ($d['categories_source'] ?? [])) ?: null],
                    ['Lieu', collect([$d['lieu'] ?? null, $d['ville'] ?? null])->filter()->implode(', ') ?: null],
                    ['Description', Str::limit(strip_tags((string) ($d['description'] ?? '')), 300) ?: null],
                ],
                'boutons' => [
                    'garder' => ['Garder', 'Publiée à la prochaine collecte de sa billetterie.', 'success'],
                    'exclure' => ['Exclure', 'N’apparaîtra jamais dans l’app.', 'danger'],
                ]],
            FileATraiter::AClasser => [...$carte,
                'question' => "Quel genre pour la catégorie « {$d['categorie']} » de {$element->cible?->nom} ?",
                'faits' => [
                    ['Annonces dans cette catégorie', isset($d['nb_annonces']) ? (string) $d['nb_annonces'] : null],
                    ['Exemples de titres', implode(' · ', array_slice((array) ($d['exemples'] ?? []), 0, 6)) ?: null],
                ],
                'boutons' => Genre::orderBy('ordre')->get()
                    ->mapWithKeys(fn (Genre $g) => ["genre:{$g->slug}" => [$g->libelle, "Toutes les annonces de cette catégorie iront en « {$g->libelle} ».", 'primary']])
                    ->put('ignorer', ['Ignorer (trop large)', 'La catégorie ne décidera plus du genre ; les mots du titre décideront.', 'gray'])
                    ->all()],
            FileATraiter::DoublonProbable, FileATraiter::FusionAControler => [...$carte,
                'question' => $element->file === FileATraiter::DoublonProbable
                    ? 'Ces deux séances sont-elles la même ?'
                    : 'Ces deux séances, fusionnées par la collecte, sont-elles bien la même ?',
                'comparaison' => [$this->seance(Offre::find($d['offre_a_id'] ?? 0)), $this->seance(Offre::find($d['offre_b_id'] ?? 0))],
                'faits' => [['Écart', isset($d['ecart_minutes']) ? "{$d['ecart_minutes']} min" : 'heure inconnue']],
                'boutons' => [
                    'fusionner' => [$element->file === FileATraiter::DoublonProbable ? 'Même séance' : 'Confirmer', 'Une seule date dans l’app, avec les deux billetteries.', 'success'],
                    'separer' => [$element->file === FileATraiter::DoublonProbable ? 'Séances différentes' : 'Séparer', 'Deux dates distinctes dans l’app.', 'danger'],
                ]],
            FileATraiter::SpectacleAControler => [...$carte,
                'question' => 'Est-ce le même spectacle dans tous ces lieux ?',
                'faits' => [['Spectacle', $element->cible?->titre ?? ($d['titre'] ?? '')], ['Pourquoi', $d['motif'] ?? null]],
                'lieux' => $this->lieux($element),
                'boutons' => [
                    'confirmer' => ['Même spectacle partout', 'Le regroupement est gardé tel quel.', 'success'],
                    'detacher' => ['Détacher les lieux cochés', 'Leurs dates passent dans un nouveau spectacle.', 'danger'],
                ]],
            default => [...$carte,
                'question' => ($d['motif'] ?? 'À vérifier').(isset($d['annonce']) ? " : {$d['annonce']}" : ''),
                'faits' => collect($d)->except(['identifiants'])->filter(fn ($v) => is_scalar($v))->map(fn ($v, $k) => [Str::ucfirst(str_replace('_', ' ', $k)), (string) $v])->values()->all()],
        };
    }

    /**
     * Applique la décision choisie sur la carte.
     *
     * @param  list<int>  $lieux  pour « detacher » : les lieux cochés
     */
    public function decider(ElementATraiter $element, string $choix, array $lieux = []): string
    {
        if ($element->statut !== StatutElement::EnAttente) {
            throw new InvalidArgumentException('Cet élément a déjà été décidé.');
        }

        if ($choix === 'ia') {
            app(AppliquerProposition::class)->handle($element->propositionIa ?? throw new InvalidArgumentException('Plus de proposition de l’IA.'));

            return 'Proposition de l’IA appliquée.';
        }

        $d = $element->donnees;

        match ([$element->file, $choix]) {
            [FileATraiter::ATrier, 'garder'] => app(DeciderTri::class)->handle($element->newCollection([$element]), IssueFiltrage::Garde),
            [FileATraiter::ATrier, 'exclure'] => app(DeciderTri::class)->handle($element->newCollection([$element]), IssueFiltrage::Exclu),
            [FileATraiter::AClasser, 'ignorer'] => app(IgnorerCategorie::class)->handle($element),
            [FileATraiter::DoublonProbable, 'fusionner'], [FileATraiter::FusionAControler, 'fusionner'],
            [FileATraiter::DoublonProbable, 'separer'], [FileATraiter::FusionAControler, 'separer'] => app(DeciderDoublon::class)->handle(
                Offre::findOrFail($d['offre_a_id']), Offre::findOrFail($d['offre_b_id']),
                $choix === 'fusionner' ? TypeDecisionDedoublonnage::Fusionner : TypeDecisionDedoublonnage::Separer),
            [FileATraiter::SpectacleAControler, 'confirmer'] => app(SeparerSpectacle::class)->confirmer(Spectacle::findOrFail($element->cible_id)),
            [FileATraiter::SpectacleAControler, 'detacher'] => app(SeparerSpectacle::class)->handle(Spectacle::findOrFail($element->cible_id), $lieux),
            default => str_starts_with($choix, 'genre:') && $element->file === FileATraiter::AClasser
                ? app(EnregistrerCorrespondanceGenre::class)->handle($element->cible, $d['categorie'], Genre::where('slug', Str::after($choix, 'genre:'))->firstOrFail())
                : throw new InvalidArgumentException("Décision inconnue : {$choix}."),
        };

        return 'Décision enregistrée.';
    }

    /** @return array<string, ?string>|null */
    private function seance(?Offre $offre): ?array
    {
        if ($offre === null) {
            return null;
        }
        $d = $offre->donnees_normalisees ?? [];
        $prix = collect([$d['prix_min'] ?? null, $d['prix_max'] ?? null])->filter(fn ($p) => $p !== null)->unique()
            ->map(fn ($p) => str_replace('.', ',', (string) $p).' €')->implode(' à ');

        return [
            'billetterie' => $offre->source?->nom,
            'titre' => $d['titre'] ?? null,
            'quand' => $offre->debut instanceof CarbonInterface
                ? $offre->debut->setTimezone('Europe/Paris')->locale('fr')->translatedFormat('D j M').($offre->heure_connue ? ' à '.$offre->debut->setTimezone('Europe/Paris')->format('H:i') : ' (heure inconnue)')
                : null,
            'lieu' => collect([$offre->lieu?->nom, $offre->lieu?->ville?->nom])->filter()->implode(', '),
            'prix' => $prix ?: null,
            'lien' => $offre->lien,
        ];
    }

    /** @return list<array{id: int, nom: string, dates: int, billetteries: string, titres: string}> */
    private function lieux(ElementATraiter $element): array
    {
        return Offre::with(['source', 'lieu.ville'])->where('spectacle_id', $element->cible_id)->whereNull('disparue_le')->get()
            ->groupBy('lieu_id')
            ->map(fn (Collection $offres, $lieuId) => [
                'id' => (int) $lieuId,
                'nom' => collect([$offres->first()->lieu?->nom, $offres->first()->lieu?->ville?->nom])->filter()->implode(', '),
                'dates' => $offres->count(),
                'billetteries' => $offres->pluck('source.nom')->unique()->implode(', '),
                'titres' => $offres->map(fn (Offre $o) => $o->donnees_normalisees['titre'] ?? null)->filter()->unique()->take(2)->implode(' · '),
            ])->sortByDesc('dates')->values()->all();
    }
}
