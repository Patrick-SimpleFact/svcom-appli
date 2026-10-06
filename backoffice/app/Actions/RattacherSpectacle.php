<?php

namespace App\Actions;

use App\Collecte\ComparaisonSeances;
use App\Enums\FileATraiter;
use App\Models\ElementATraiter;
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
 * Le spectacle n'est visible dans l'app qu'avec des représentations (K08).
 */
class RattacherSpectacle
{
    private const ARTISTES_COMMUNS = 3;

    private const MEME_LIEU_OU_BILLETTERIE = 2;

    private const TITRE_SEUL = 1;

    /** @var array<int, list<string>> artistes connus de chaque spectacle (pendant cette collecte) */
    private array $artistesDesSpectacles = [];

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

        if ($spectacle === null) {
            [$spectacle, $niveau] = $this->memeTitre($offre);

            if ($spectacle !== null && $niveau === self::TITRE_SEUL) {
                $this->aControler($spectacle);
            }
        }

        $spectacle ??= $this->creer($offre);

        // Toutes les offres de la séance suivent le même spectacle.
        $premiere = $offre->meme_seance_que_id ?? $offre->id;
        Offre::where(fn ($q) => $q->whereKey($premiere)->orWhere('meme_seance_que_id', $premiere))
            ->update(['spectacle_id' => $spectacle->id]);

        unset($this->artistesDesSpectacles[$spectacle->id]); // ses artistes viennent peut-être de changer

        return $spectacle;
    }

    public function estGenerique(?string $titreComparable): bool
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
     *
     * @return array{0: ?Spectacle, 1: ?int} le spectacle et la force du rapprochement
     */
    private function memeTitre(Offre $offre): array
    {
        if (blank($offre->titre_comparable)) {
            return [null, null];
        }

        $candidats = Offre::whereKeyNot($offre->id)
            ->whereNotNull('spectacle_id')
            ->where('titre_comparable', $offre->titre_comparable)
            ->when($this->estGenerique($offre->titre_comparable), fn ($q) => $q->where('lieu_id', $offre->lieu_id))
            ->distinct()
            ->orderBy('spectacle_id')
            ->limit(50)
            ->pluck('spectacle_id');

        $artistes = $this->artistes($offre);
        $meilleur = [null, null];

        foreach ($candidats as $spectacleId) {
            $artistesDuSpectacle = $this->artistesDuSpectacle($spectacleId);
            $offres = Offre::where('spectacle_id', $spectacleId);

            $niveau = match (true) {
                $artistes !== [] && $artistesDuSpectacle !== [] => array_intersect($artistes, $artistesDuSpectacle) !== [] ? self::ARTISTES_COMMUNS : null,
                (clone $offres)->where(fn ($q) => $q->where('lieu_id', $offre->lieu_id)->orWhere('source_id', $offre->source_id))->exists() => self::MEME_LIEU_OU_BILLETTERIE,
                default => self::TITRE_SEUL,
            };

            if ($niveau !== null && $niveau > ($meilleur[1] ?? 0)) {
                $meilleur = [$spectacleId, $niveau];
            }
        }

        return [$meilleur[0] ? Spectacle::find($meilleur[0]) : null, $meilleur[1]];
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

    /** Le spectacle réunit des dates sur la foi du titre seul : à contrôler (une ligne par spectacle). */
    private function aControler(Spectacle $spectacle): void
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
                'donnees' => ['titre' => $spectacle->titre, 'motif' => 'Dates de plusieurs lieux réunies sur le titre seul (artistes inconnus)'],
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
