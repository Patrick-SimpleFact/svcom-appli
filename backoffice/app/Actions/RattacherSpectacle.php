<?php

namespace App\Actions;

use App\Collecte\ComparaisonSeances;
use App\Enums\FileATraiter;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Offre;
use App\Models\Spectacle;
use App\Support\Texte;
use Illuminate\Support\Facades\DB;

/**
 * Étape « spectacle » de la chaîne (COLLECTE §7.3, F5.3) : chaque séance collectée est rattachée à un spectacle,
 * pour les « Autres dates » et les tournées. Le titre seul ne suffit pas (demande de Patrick, K07b) :
 * 1. le spectacle de son groupe de séances (même séance vendue par une autre billetterie, K06) ;
 * 2. sinon, un spectacle au même titre, à condition que les artistes concordent :
 *    - artistes connus des deux côtés : au moins un en commun, sinon ce sont deux spectacles ;
 *    - artistes inconnus d'un côté : même lieu (BilletRéduc le vendredi, la Fnac le samedi) ou même billetterie
 *      (sa tournée, Patrick lui fait confiance) → regroupé ; autre billetterie dans un autre lieu (titre seul) → regroupé,
 *      mais le spectacle va dans « Spectacles à contrôler » ;
 * 3. sinon un nouveau spectacle.
 * Un titre générique (« Concert », « Spectacle de Noël ») n'est jamais regroupé entre deux lieux différents.
 * Sur le titre seul, plusieurs spectacles possibles (« L'Avare » de plusieurs troupes) : aucun n'est choisi, le nouveau
 * spectacle va dans « Spectacles à contrôler ». Une lettre masquée (« c*ns ») vaut le titre connu qui lui correspond, et
 * la catégorie retirée en tête du titre (« Opéra - Carmen ») écarte les spectacles d'un autre genre.
 * Le spectacle n'est visible dans l'app qu'avec des représentations (K08).
 */
class RattacherSpectacle
{
    public const ARTISTES_COMMUNS = 3;

    public const MEME_LIEU_OU_BILLETTERIE = 2;

    public const TITRE_SEUL = 1;

    /** Le titre seul désigne plusieurs spectacles : aucun n'est choisi. */
    public const AMBIGU = 0;

    private const MOTIF_TITRE_SEUL = 'Dates de plusieurs lieux réunies sur le titre seul (artistes inconnus)';

    public const MOTIF_AMBIGU = 'Plusieurs spectacles portent ce titre : rien n’a été réuni';

    /** @var array<int, list<string>> artistes connus de chaque spectacle (pendant cette collecte) */
    private array $artistesDesSpectacles = [];

    /** @var array<string, ?string>|null titres masqués connus → le titre en clair qu'ils désignent (pendant cette collecte) */
    private ?array $masquesConnus = null;

    /** Mots qui, seuls, ne désignent pas un spectacle précis. */
    private const MOTS_GENERIQUES = [
        'concert', 'concerts', 'spectacle', 'spectacles', 'noel', 'soiree', 'gala', 'recital', 'fete', 'fetes', 'musique',
        'musiques', 'theatre', 'humour', 'danse', 'cirque', 'opera', 'festival', 'bal', 'apero', 'cabaret', 'piano',
        'jazz', 'orgue', 'chorale', 'choeur', 'conte', 'contes', 'lecture', 'scene', 'ouverte', 'jam', 'session',
        'enfants', 'enfant', 'jeune', 'public', 'famille', 'improvisation', 'impro', 'match', 'nouvel', 'an',
        'annee', 'printemps', 'ete', 'automne', 'hiver', 'classique', 'live', 'dj', 'set', 'gratuit',
    ];

    public function handle(Offre $offre): Spectacle
    {
        $spectacle = $this->spectacleDuGroupe($offre);

        $ambigu = false;

        if ($spectacle === null) {
            [$spectacle, $niveau] = $this->memeTitre($offre);
            $ambigu = $niveau === self::AMBIGU;

            if ($spectacle !== null && $niveau === self::TITRE_SEUL) {
                $this->aControler($spectacle);
            }
        }

        if ($spectacle === null) {
            $spectacle = $this->creer($offre);

            if ($ambigu) {
                $this->aControler($spectacle, self::MOTIF_AMBIGU);
            }
        }

        // Toutes les offres de la séance suivent le même spectacle.
        $premiere = $offre->meme_seance_que_id ?? $offre->id;
        Offre::where(fn ($q) => $q->whereKey($premiere)->orWhere('meme_seance_que_id', $premiere))
            ->update(['spectacle_id' => $spectacle->id]);

        unset($this->artistesDesSpectacles[$spectacle->id]); // ses artistes viennent peut-être de changer

        return $spectacle;
    }

    public static function estGenerique(?string $titreComparable): bool
    {
        $mots = array_filter(explode(' ', (string) $titreComparable));

        return mb_strlen((string) $titreComparable) < 4 || array_diff($mots, self::MOTS_GENERIQUES) === [];
    }

    private function spectacleDuGroupe(Offre $offre): ?Spectacle
    {
        $premiere = $offre->meme_seance_que_id ?? $offre->id;
        $id = Offre::where(fn ($q) => $q->whereKey($premiere)->orWhere('meme_seance_que_id', $premiere))
            ->whereKeyNot($offre->id)
            ->whereNotNull('spectacle_id')
            ->orderBy('id')
            ->value('spectacle_id');

        return $id ? Spectacle::find($id) : null;
    }

    /**
     * Le meilleur spectacle au même titre dont les artistes ne contredisent pas ceux de la séance.
     * $sauf : le spectacle actuel de la séance, écarté (rattrapage des titres, commande spectacles:rapprocher-titres).
     *
     * @return array{0: ?Spectacle, 1: ?int} le spectacle et la force du rapprochement (AMBIGU : plusieurs sur le titre seul)
     */
    public function memeTitre(Offre $offre, ?int $sauf = null): array
    {
        if (blank($offre->titre_comparable)) {
            return [null, null];
        }

        $genres = $this->genresCompatibles($offre);

        $candidats = Offre::whereKeyNot($offre->id)
            ->whereNotNull('spectacle_id')
            ->whereIn('titre_comparable', $this->titresEquivalents($offre->titre_comparable))
            ->when($this->estGenerique($offre->titre_comparable), fn ($q) => $q->where('lieu_id', $offre->lieu_id))
            ->when($sauf !== null, fn ($q) => $q->where('spectacle_id', '!=', $sauf))
            ->when($genres !== null, fn ($q) => $q->whereIn('spectacle_id', Spectacle::select('id')->whereIn('genre_id', $genres)))
            ->distinct()
            ->orderBy('spectacle_id')
            ->limit(50)
            ->pluck('spectacle_id');

        $artistes = $this->artistes($offre);
        $meilleur = [null, null];
        $surLeTitreSeul = 0;

        foreach ($candidats as $spectacleId) {
            $artistesDuSpectacle = $this->artistesDuSpectacle($spectacleId);
            $offres = Offre::where('spectacle_id', $spectacleId);

            $niveau = match (true) {
                $artistes !== [] && $artistesDuSpectacle !== [] => array_intersect($artistes, $artistesDuSpectacle) !== [] ? self::ARTISTES_COMMUNS : null,
                (clone $offres)->where(fn ($q) => $q->where('lieu_id', $offre->lieu_id)->orWhere('source_id', $offre->source_id))->exists() => self::MEME_LIEU_OU_BILLETTERIE,
                default => self::TITRE_SEUL,
            };

            if ($niveau === self::TITRE_SEUL) {
                $surLeTitreSeul++;
            }

            if ($niveau !== null && $niveau > ($meilleur[1] ?? 0)) {
                $meilleur = [$spectacleId, $niveau];
            }
        }

        if ($meilleur[1] === self::TITRE_SEUL && $surLeTitreSeul > 1) {
            return [null, self::AMBIGU];
        }

        return [$meilleur[0] ? Spectacle::find($meilleur[0]) : null, $meilleur[1]];
    }

    /**
     * Le titre, et le titre qui lui correspond quand une lettre est masquée d'un côté ou de l'autre
     * (« oh p* laurent » / « oh putain laurent »), seulement s'il n'y en a qu'un.
     *
     * @return list<string>
     */
    public function titresEquivalents(string $titre): array
    {
        if (str_contains($titre, '*')) {
            $correspondant = $this->titreDuMasque($titre);

            return $correspondant === null ? [$titre] : [$titre, $correspondant];
        }

        // Les titres masqués connus, lus une fois par collecte (ils sont rares) : seulement ceux qui désignent ce titre.
        $this->masquesConnus ??= Offre::where('titre_comparable', 'like', '%*%')->distinct()->pluck('titre_comparable')
            ->mapWithKeys(fn (string $masque) => [$masque => $this->titreDuMasque($masque)])->all();

        return [$titre, ...array_keys(array_filter($this->masquesConnus, fn (?string $correspondant) => $correspondant === $titre))];
    }

    /** L'unique titre en clair qui correspond au titre masqué, ou null (aucun, ou plusieurs). */
    private function titreDuMasque(string $masque): ?string
    {
        $trouves = Offre::whereRaw('titre_comparable ~ ?', ['^'.str_replace('*', '[a-z]+', $masque).'$']) // seulement [a-z0-9 *]
            ->where('titre_comparable', 'not like', '%*%')
            ->distinct()->limit(2)->pluck('titre_comparable');

        return $trouves->count() === 1 ? $trouves->first() : null;
    }

    /**
     * Genres admis par la catégorie retirée en tête du titre (« Opéra - Carmen » : opéra), ou null.
     *
     * @return list<int>|null
     */
    private function genresCompatibles(Offre $offre): ?array
    {
        if (! in_array($offre->source?->code, ComparaisonSeances::SOURCES_CATEGORIE_EN_TETE, true)) {
            return null;
        }

        $genres = app(ComparaisonSeances::class)->categorieEnTete((string) ($offre->donnees_normalisees['titre'] ?? ''))['genres'] ?? null;

        return $genres === null ? null : Genre::whereIn('slug', [...$genres, 'autres'])->pluck('id')->all();
    }

    /** @return list<string> */
    private function artistesDuSpectacle(int $spectacleId): array
    {
        return $this->artistesDesSpectacles[$spectacleId] ??= collect(DB::select(
            "select distinct jsonb_array_elements_text(donnees_normalisees->'artistes') as nom, titre_comparable from offres
             where spectacle_id = ? and jsonb_typeof(donnees_normalisees->'artistes') = 'array'",
            [$spectacleId],
        ))->filter(fn ($ligne) => $this->vraiArtiste($ligne->nom, (string) $ligne->titre_comparable))
            ->map(fn ($ligne) => Texte::normaliser($ligne->nom))->unique()->values()->all();
    }

    /**
     * La Fnac donne parfois le nom du spectacle comme « artiste » (« Le Flocon Magique ») : ce n'est pas une troupe.
     * Un vrai nom en tête du titre reste un artiste (« Emma Bojan » dans « Emma Bojan – Attends-moi j'arrive »).
     */
    private function vraiArtiste(string $nom, string $titreComparable): bool
    {
        $nom = app(ComparaisonSeances::class)->titreComparable($nom);

        if ($nom === '' || $titreComparable === '') {
            return $nom !== '';
        }

        $proche = str_contains($titreComparable, $nom) || str_contains($nom, $titreComparable);

        return ! ($proche && mb_strlen($nom) >= 0.7 * mb_strlen($titreComparable));
    }

    /** Le spectacle réunit des dates sur la foi du titre seul, ou son titre est ambigu : à contrôler (une ligne par spectacle). */
    public function aControler(Spectacle $spectacle, string $motif = self::MOTIF_TITRE_SEUL): void
    {
        $dejaVu = ElementATraiter::where('file', FileATraiter::SpectacleAControler)
            ->where('cible_type', $spectacle->getMorphClass())
            ->where('cible_id', $spectacle->id)
            ->exists(); // en attente, ou déjà contrôlé : on ne redemande pas

        if (! $dejaVu) {
            ElementATraiter::create([
                'file' => FileATraiter::SpectacleAControler,
                'cible_type' => $spectacle->getMorphClass(),
                'cible_id' => $spectacle->id,
                'donnees' => ['titre' => $spectacle->titre, 'motif' => $motif],
            ]);
        }
    }

    /** @return list<string> */
    private function artistes(Offre $offre): array
    {
        return collect($offre->donnees_normalisees['artistes'] ?? [])
            ->filter(fn ($nom) => is_string($nom) && $this->vraiArtiste($nom, (string) $offre->titre_comparable))
            ->map(fn (string $nom) => Texte::normaliser($nom))->filter()->unique()->values()->all();
    }

    private function creer(Offre $offre): Spectacle
    {
        $donnees = $offre->donnees_normalisees;

        // Création automatique : pas de verrouillage ni de journal (ce n'est pas une correction à la main).
        return Spectacle::withoutEvents(fn () => Spectacle::create([
            'titre' => mb_substr($donnees['titre'], 0, 255),
            'description' => $donnees['description'] ?? null,
            'genre_id' => $offre->genre_id,
            'classification_fine' => isset($donnees['categories_source'][0]) ? mb_substr($donnees['categories_source'][0], 0, 255) : null,
            'jeune_public' => $offre->jeune_public,
            'image_url' => isset($donnees['image_url']) ? mb_substr($donnees['image_url'], 0, 1000) : null,
        ]));
    }
}
