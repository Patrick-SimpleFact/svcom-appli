<?php

namespace App\Ia;

use App\Actions\DeciderDoublon;
use App\Actions\DeciderTri;
use App\Actions\EnregistrerCorrespondanceGenre;
use App\Actions\FusionnerLieux;
use App\Actions\IgnorerCategorie;
use App\Actions\MasquerRepresentation;
use App\Actions\PublierSource;
use App\Actions\RattacherLieu;
use App\Actions\SeparerSpectacle;
use App\Enums\FileATraiter;
use App\Enums\IssueFiltrage;
use App\Enums\ModeIa;
use App\Enums\PrecisionPosition;
use App\Enums\StatutElement;
use App\Enums\TypeDecisionDedoublonnage;
use App\Filament\Pages\BoiteDeTravail;
use App\Filament\Resources\Lieux\LieuResource;
use App\Filament\Resources\LieuxAVerifier\LieuAVerifierResource;
use App\Filament\Support\Urgence;
use App\Models\Admin;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Support\Texte;
use Carbon\CarbonImmutable;
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
 * Les cartes arrivées le plus récemment passent en premier (jour par jour), la plus urgente d'abord dans un même jour :
 * l'app sort dans quelques semaines, les anciennes annonces auront disparu d'ici là.
 */
class MesActions
{
    /** Arrivée dans « Mes actions » : le dernier examen de l'IA (doute ou proposition), sinon l'entrée dans la file. */
    private const ARRIVEE = "coalesce((select max(p.created_at) from propositions_ia p where p.element_a_traiter_id = elements_a_traiter.id and p.statut in ('doute', 'proposee')), elements_a_traiter.created_at)";

    /** Lieux connus proposés pour un rattachement : à moins de cette distance du lieu à vérifier (m). */
    public const RAYON_LIEUX_PROCHES = 300;

    private const JOUR_ARRIVEE = '(('.self::ARRIVEE.") at time zone 'Europe/Paris')::date";

    public function __construct(private Assistant $assistant) {}

    /** Les files ouvertes à l'admin connecté (modèle de profil), ou toutes hors connexion (e-mail du matin). @return list<FileATraiter> */
    public function files(): array
    {
        $ressources = auth()->user() instanceof Admin ? BoiteDeTravail::ressourcesOuvertes() : BoiteDeTravail::RESSOURCES;

        return array_map(fn (string $f) => FileATraiter::from($f), array_keys($ressources));
    }

    public function requete(): Builder
    {
        $avecIa = collect($this->files())->filter(fn (FileATraiter $f) => isset(Assistant::TACHES[$f->value]) && $this->assistant->tache($f)->mode() !== ModeIa::Desactive);
        $sansIa = collect($this->files())->reject(fn (FileATraiter $f) => $avecIa->contains($f));

        return ElementATraiter::query()
            ->select('elements_a_traiter.*')->selectRaw(self::ARRIVEE.' as arrivee')
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

    /** Les cartes arrivées aujourd'hui (heure de Paris) : au minimum, ce que la nuit a ajouté. */
    public function nouvelles(): int
    {
        return $this->requete()->whereRaw(self::JOUR_ARRIVEE.' = ?', [now('Europe/Paris')->toDateString()])->count();
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
        return Urgence::trier($this->requete()->whereKeyNot($passes)->orderByRaw(self::JOUR_ARRIVEE.' desc'))->first();
    }

    /**
     * Ce qu'affiche la carte : question, faits, avis de l'IA, boutons (clé => [libellé, conséquence, couleur]).
     *
     * @return array{file: string, urgence: ?string, ajoutee: string, question: string, faits: list<array{0: string, 1: ?string, 2?: ?string}>, comparaison: ?array, lieux: ?array, lieuAVerifier: ?array, billetteries: list<array{0: string, 1: string}>, masquer: list<array{0: string, 1: int, 2: string, 3: string}>, avis: ?string, proposition: ?string, boutons: array<string, array{0: string, 1: string, 2: string}>, lien: ?string}
     */
    public function carte(ElementATraiter $element): array
    {
        $d = $element->donnees ?? [];
        $carte = [
            'file' => $element->file->getLabel(),
            'urgence' => $element->urgence >= 2 ? Urgence::LIBELLES[$element->urgence] : null,
            'ajoutee' => $this->ajoutee($element),
            'faits' => [], 'comparaison' => null, 'lieux' => null, 'lieuAVerifier' => null, 'boutons' => [], 'billetteries' => [], 'masquer' => [],
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
                    ['Titre', $d['titre'] ?? ''],
                    ['Billetterie', $element->cible?->nom],
                    ['Catégories', implode(' › ', (array) ($d['categories_source'] ?? [])) ?: null],
                    ['Lieu', collect([$d['lieu'] ?? null, $d['ville'] ?? null])->filter()->implode(', ') ?: null],
                    ['Description', Str::limit(strip_tags((string) ($d['description'] ?? '')), 300) ?: null],
                ],
                'billetteries' => array_filter([filled($d['lien'] ?? null) ? [$element->cible?->nom ?? 'Billetterie', $d['lien']] : null]),
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
                'billetteries' => Offre::where('source_id', $element->cible_id)->whereNull('disparue_le')->whereNotNull('lien')
                    ->whereJsonContains('donnees_normalisees->categories_source', $d['categorie'] ?? '')->latest('id')->limit(5)->get()
                    ->map(fn (Offre $o) => [Str::limit((string) ($o->donnees_normalisees['titre'] ?? 'Annonce'), 60), $o->lien])->all(),
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
                'masquer' => $element->cible instanceof Spectacle && ! $element->cible->masque
                    ? [['spectacle', $element->cible_id, 'Masquer le spectacle', 'Le spectacle et toutes ses dates disparaissent de l’app.']] : [],
                'boutons' => [
                    'confirmer' => ['Même spectacle partout', 'Le regroupement est gardé tel quel.', 'success'],
                    'repartir' => ['Séparer selon les numéros', 'Le spectacle 1 garde ses dates ; chaque autre numéro devient un nouveau spectacle.', 'danger'],
                ]],
            FileATraiter::LieuAVerifier => [...$carte, ...$this->carteLieu($element)],
            default => [...$carte,
                'question' => ($d['motif'] ?? 'À vérifier').(isset($d['annonce']) ? " : {$d['annonce']}" : ''),
                'faits' => collect($d)->except(['identifiants', 'lien'])->filter(fn ($v) => is_scalar($v))->map(fn ($v, $k) => [Str::ucfirst(str_replace('_', ' ', $k)), (string) $v])->values()->all(),
                'billetteries' => array_filter([filled($d['lien'] ?? null) ? ['Annonce d’origine', $d['lien']] : null])],
        };
    }

    /**
     * Applique la décision choisie sur la carte.
     *
     * @param  list<int>  $lieux  pour « detacher » : les lieux cochés
     * @param  ?string  $nom  pour « renommer » (lieu à vérifier) : le vrai nom
     * @param  ?int  $lieuId  pour « rattacher » (lieu à vérifier) : le lieu connu qui le remplace
     * @param  array<int, int>  $groupes  pour « repartir » : lieu_id => numéro de spectacle
     */
    public function decider(ElementATraiter $element, string $choix, array $lieux = [], ?string $nom = null, ?int $lieuId = null, array $groupes = []): string
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
            [FileATraiter::SpectacleAControler, 'repartir'] => app(SeparerSpectacle::class)->repartir(Spectacle::findOrFail($element->cible_id), $groupes),
            [FileATraiter::LieuAVerifier, 'verifie'] => LieuAVerifierResource::marquerVerifies($element->newCollection([$element])),
            [FileATraiter::LieuAVerifier, 'renommer'] => $this->renommerLieu($element, (string) $nom),
            [FileATraiter::LieuAVerifier, 'rattacher'] => app(FusionnerLieux::class)->handle(
                Lieu::findOrFail($element->cible_id), Lieu::actifs()->findOrFail($lieuId ?? throw new InvalidArgumentException('Choisir le lieu connu.'))),
            default => str_starts_with($choix, 'genre:') && $element->file === FileATraiter::AClasser
                ? app(EnregistrerCorrespondanceGenre::class)->handle($element->cible, $d['categorie'], Genre::where('slug', Str::after($choix, 'genre:'))->firstOrFail())
                : throw new InvalidArgumentException("Décision inconnue : {$choix}."),
        };

        return 'Décision enregistrée.';
    }

    /**
     * « Supprimer » depuis une carte : on masque (immédiat, réversible depuis la fiche, jamais réaffiché par la collecte).
     * - spectacle, lieu : masqués dans l'app avec toutes leurs dates ;
     * - seance (une offre) : sa représentation est masquée ; dates (un lieu) : toutes les dates du spectacle de la carte à ce lieu ;
     * - lien (une offre) : ce lien de billetterie est retiré de sa séance, les autres billetteries restent.
     * Seul ce que montre la carte peut être masqué. La carte est traitée quand ce qu'elle demande n'a plus d'objet :
     * le spectacle à contrôler, le lieu à vérifier, ou une des deux séances à comparer.
     */
    public function masquer(ElementATraiter $element, string $quoi, int $id): string
    {
        if ($element->statut !== StatutElement::EnAttente) {
            throw new InvalidArgumentException('Cet élément a déjà été décidé.');
        }

        $d = $element->donnees ?? [];
        $offresCarte = match ($element->file) {
            FileATraiter::DoublonProbable, FileATraiter::FusionAControler => Offre::whereKey(array_filter([$d['offre_a_id'] ?? null, $d['offre_b_id'] ?? null])),
            FileATraiter::SpectacleAControler => Offre::where('spectacle_id', $element->cible_id),
            FileATraiter::LieuAVerifier => Offre::where('lieu_id', $element->cible_id),
            default => Offre::whereRaw('false'),
        };
        $offre = in_array($quoi, ['seance', 'lien'], true) ? (clone $offresCarte)->findOrFail($id) : null;
        $surLaCarte = fn (string $colonne, string $classe): int => (clone $offresCarte)->where($colonne, $id)->exists()
            || ($element->cible instanceof $classe && (int) $element->cible_id === $id) ? $id : throw new InvalidArgumentException('Cet élément n’est pas sur la carte.');

        $message = match ($quoi) {
            'spectacle' => $this->masquerModele(Spectacle::findOrFail($surLaCarte('spectacle_id', Spectacle::class)), 'Spectacle masqué'),
            'lieu' => $this->masquerModele(Lieu::findOrFail($surLaCarte('lieu_id', Lieu::class)), 'Lieu masqué'),
            'seance' => $this->masquerRepresentations([$offre->representation_id], 'Séance masquée'),
            'dates' => $this->masquerRepresentations((clone $offresCarte)->where('lieu_id', $id)->whereNotNull('representation_id')->distinct()->pluck('representation_id')->all(), 'Dates masquées'),
            'lien' => $this->retirerLien($offre),
            default => throw new InvalidArgumentException("Masquage inconnu : {$quoi}."),
        };

        $sansObjet = match ($element->file) {
            FileATraiter::DoublonProbable, FileATraiter::FusionAControler => true,
            FileATraiter::SpectacleAControler => $quoi === 'spectacle',
            FileATraiter::LieuAVerifier => $quoi === 'lieu',
            default => false,
        };

        if ($sansObjet) {
            $element->update(['statut' => StatutElement::Traite, 'decision' => ['masque' => $quoi, 'id' => $id],
                'traite_par' => auth()->user() instanceof Admin ? auth()->id() : null, 'traite_le' => now()]);
        }

        return $message.' (réversible depuis sa fiche).';
    }

    private function masquerModele(Spectacle|Lieu $modele, string $message): string
    {
        $modele->update(['masque' => true]);

        return $message;
    }

    /** @param  list<?int>  $ids */
    private function masquerRepresentations(array $ids, string $message): string
    {
        $representations = Representation::whereKey(array_filter($ids))->get();

        if ($representations->isEmpty()) {
            throw new InvalidArgumentException('Pas encore de date publiée à masquer.');
        }

        $representations->each(fn (Representation $r) => app(MasquerRepresentation::class)->masquer($r));

        return $message;
    }

    private function retirerLien(Offre $offre): string
    {
        $offre->update(['masquee' => true, 'disparue_le' => $offre->disparue_le ?? now()]);
        app(PublierSource::class)->publierGroupes($offre->newCollection([$offre]));

        return 'Lien retiré de la séance';
    }

    /** Lieu à vérifier : ce qu'a écrit la billetterie, l'adresse sur une carte, les lieux connus tout près. @return array<string, mixed> */
    private function carteLieu(ElementATraiter $element): array
    {
        $d = $element->donnees ?? [];
        $lieu = $element->cible instanceof Lieu ? $element->cible->loadMissing('ville') : null;
        $adresse = collect([$lieu?->adresse, $lieu?->ville?->nom])->filter()->unique()->implode(', ');
        $offres = $lieu ? Offre::where('lieu_id', $lieu->id)->whereNull('disparue_le') : null;

        return [
            'question' => ($d['motif'] ?? null) === RattacherLieu::MOTIF_NOM_IMPRECIS
                ? 'Quel est le vrai nom de ce lieu ?'
                : 'Ce lieu est-il bien identifié ?',
            'faits' => [
                ['Nom affiché dans l’app', $lieu?->nom],
                ['Adresse', $adresse ?: null, $adresse ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($adresse) : null],
                ['Pourquoi', $d['motif'] ?? null],
                ['Écrit par la billetterie', collect([$d['nom_source'] ?? null, $d['adresse_source'] ?? null, $d['ville_source'] ?? null])->filter()->implode(' · ') ?: null],
                ['Billetterie', $d['source'] ?? null],
                ['Vu dans', $d['annonce'] ?? null],
                ['Séances à venir', $offres ? (string) (clone $offres)->where('debut', '>=', now())->count() : null],
            ],
            'billetteries' => $offres ? (clone $offres)->whereNotNull('lien')->with('source')->latest('id')->limit(3)->get()
                ->map(fn (Offre $o) => [Str::limit((string) ($o->donnees_normalisees['titre'] ?? $o->source?->nom ?? 'Annonce'), 50), $o->lien])->all() : [],
            'lieuAVerifier' => $lieu ? [
                'proches' => $this->lieuxProches($lieu),
                'fiche' => LieuResource::getUrl('edit', ['record' => $lieu]),
            ] : null,
            'masquer' => $lieu && ! $lieu->masque ? [['lieu', $lieu->id, 'Masquer le lieu', 'Le lieu et toutes ses séances disparaissent de l’app.']] : [],
            'boutons' => ['verifie' => ['Le lieu est bon', 'Il est gardé tel quel et sort de la liste.', 'success']],
        ];
    }

    /** @return list<array{id: int, nom: string, distance: int}> */
    private function lieuxProches(Lieu $lieu): array
    {
        if ($lieu->position === null || $lieu->precision_position === PrecisionPosition::Commune) {
            return [];
        }
        $point = $lieu->position->versEwkt();

        return Lieu::actifs()->with('ville')->whereKeyNot($lieu->id)->whereNotNull('position')
            ->whereRaw('ST_DWithin(position, ?::geography, ?)', [$point, self::RAYON_LIEUX_PROCHES])
            ->selectRaw('lieux.*, round(ST_Distance(position, ?::geography)) as distance', [$point])
            ->orderBy('distance')->limit(6)->get()
            ->map(fn (Lieu $l) => ['id' => $l->id, 'nom' => collect([$l->nom, $l->adresse])->filter()->unique()->implode(' — '), 'distance' => (int) $l->getAttribute('distance')])
            ->all();
    }

    /**
     * Lieux connus dont le nom ou l'adresse contient la recherche, ceux de la même commune d'abord.
     *
     * @return list<array{id: int, nom: string}>
     */
    public function chercherLieux(ElementATraiter $element, string $recherche): array
    {
        $texte = Texte::normaliser($recherche);
        if (mb_strlen($texte) < 2 || ! $element->cible instanceof Lieu) {
            return [];
        }

        return Lieu::actifs()->with('ville')->whereKeyNot($element->cible_id)
            ->where(fn (Builder $q) => $q->where('nom_normalise', 'like', "%{$texte}%")->orWhere('adresse', 'ilike', '%'.trim($recherche).'%'))
            ->orderByRaw('ville_id is not distinct from ? desc', [$element->cible->ville_id])->orderBy('nom')->limit(8)->get()
            ->map(fn (Lieu $l) => ['id' => $l->id, 'nom' => collect([$l->nom, $l->adresse, $l->ville?->nom])->filter()->unique()->implode(' — ')])
            ->all();
    }

    private function renommerLieu(ElementATraiter $element, string $nom): void
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 255) {
            throw new InvalidArgumentException('Écrire le vrai nom du lieu (255 caractères au plus).');
        }

        // Corrigé par un admin : le nom est verrouillé, la collecte ne l'écrasera plus.
        Lieu::findOrFail($element->cible_id)->update(['nom' => $nom]);
        LieuAVerifierResource::marquerVerifies($element->newCollection([$element]));
    }

    /** « Ajoutée aujourd'hui (ven. 23 oct.) », « Ajoutée hier (jeu. 22 oct.) », « Ajoutée le lun. 19 oct. ». */
    private function ajoutee(ElementATraiter $element): string
    {
        $jour = CarbonImmutable::parse($element->getAttribute('arrivee') ?? $element->created_at)->setTimezone('Europe/Paris')->locale('fr');
        $date = $jour->translatedFormat('D j M');
        $aujourdhui = CarbonImmutable::now('Europe/Paris');

        return match (true) {
            $jour->isSameDay($aujourdhui) => "Ajoutée aujourd’hui ({$date})",
            $jour->isSameDay($aujourdhui->subDay()) => "Ajoutée hier ({$date})",
            default => "Ajoutée le {$date}",
        };
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
            'masquer' => $offre->masquee ? [] : array_values(array_filter([
                $offre->representation_id ? ['seance', $offre->id, 'Masquer la séance', 'La date disparaît de l’app, toutes billetteries confondues.'] : null,
                filled($offre->lien) ? ['lien', $offre->id, 'Retirer ce lien', 'Seul le lien '.($offre->source?->nom ?? 'de cette billetterie').' disparaît de cette séance.'] : null,
                $offre->lieu && ! $offre->lieu->masque ? ['lieu', $offre->lieu_id, 'Masquer le lieu', 'Le lieu et toutes ses séances disparaissent de l’app.'] : null,
                $offre->spectacle && ! $offre->spectacle->masque ? ['spectacle', $offre->spectacle_id, 'Masquer le spectacle', 'Le spectacle et toutes ses dates disparaissent de l’app.'] : null,
            ])),
        ];
    }

    /** @return list<array{id: int, nom: string, dates: int, billetteries: string, titres: string, liens: list<array{0: string, 1: string}>, masque: bool, masquer: list<array{0: string, 1: int, 2: string, 3: string}>}> */
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
                'liens' => $offres->filter(fn (Offre $o) => filled($o->lien))->unique('source_id')->map(fn (Offre $o) => [$o->source?->nom ?? 'Billetterie', $o->lien])->values()->all(),
                'masque' => (bool) $offres->first()->lieu?->masque,
                'masquer' => $offres->first()->lieu?->masque ? [] : [
                    ['dates', (int) $lieuId, 'Masquer ces dates', 'Les dates de ce spectacle dans ce lieu disparaissent de l’app.'],
                    ['lieu', (int) $lieuId, 'Masquer le lieu', 'Le lieu et toutes ses séances, tous spectacles confondus, disparaissent de l’app.'],
                ],
            ])->sortByDesc('dates')->values()->all();
    }
}
