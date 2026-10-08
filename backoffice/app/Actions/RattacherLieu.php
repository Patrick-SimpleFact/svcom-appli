<?php

namespace App\Actions;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\BaseAdresseNationale;
use App\Collecte\ComparaisonLieux;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\TypeLieu;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\LieuSource;
use App\Models\Source;
use App\Models\Ville;
use App\Support\CodeInsee;
use App\Support\Point;
use App\Support\Texte;
use Illuminate\Support\Facades\DB;

/**
 * Étape « lieux » de la chaîne (COLLECTE §4, F7.5) : chaque annonce gardée est rattachée à un lieu du catalogue.
 * 1. lieu déjà vu chez cette source ; 2-3. rapprochement avec un lieu existant (référentiel du Ministère compris) :
 * nom proche et < 200 m, ou même adresse ; 4. sinon nouveau lieu (position de la source, sinon Base Adresse
 * Nationale, sinon centre de la commune = « approximative ») ; 5. fuseau horaire de la commune.
 * Les nouveaux lieux et les rapprochements incertains vont dans « Lieux à vérifier », mais sont publiés quand même.
 */
class RattacherLieu
{
    /** Zones où une position fournie par une source est plausible : métropole et outre-mer. [lat min, lat max, lon min, lon max] */
    private const ZONES_FRANCE = [
        [41.2, 51.2, -5.3, 9.7],        // métropole et Corse
        [14.3, 18.2, -63.2, -60.7],     // Antilles
        [2.0, 6.0, -54.7, -51.5],       // Guyane
        [-21.5, -20.8, 55.1, 55.9],     // La Réunion
        [-13.1, -12.5, 44.9, 45.4],     // Mayotte
        [46.7, 47.2, -56.5, -56.1],     // Saint-Pierre-et-Miquelon
        [-23.0, -19.0, 163.0, 169.0],   // Nouvelle-Calédonie
        [-28.0, -7.0, -155.0, -134.0],  // Polynésie
        [-14.5, -13.0, -178.5, -176.0], // Wallis-et-Futuna
    ];

    /** Au-delà, la position donnée par la source est trop loin de sa propre commune pour être crue (km). */
    private const ECART_COMMUNE_MAX_KM = 50;

    /** @var array<string, int> lieux déjà résolus pendant cette collecte (clé → lieu) */
    private array $memoire = [];

    public function __construct(
        private BaseAdresseNationale $ban,
        private ComparaisonLieux $comparaison,
    ) {}

    public function handle(AnnonceNormalisee $annonce, Source $source): Lieu
    {
        $cle = $this->cle($annonce);

        // 1. Lieu déjà connu de cette source.
        $lieuId = $this->memoire["{$source->id}:{$cle}"]
            ??= LieuSource::where('source_id', $source->id)->where('cle', $cle)->value('lieu_id');

        if ($lieuId !== null) {
            return $this->nommer($this->lieuFinal(Lieu::findOrFail($lieuId)), $annonce);
        }

        $lieu = DB::transaction(function () use ($annonce, $source, $cle) {
            $lieu = $this->rattacherOuCreer($annonce, $source);

            LieuSource::create([
                'source_id' => $source->id,
                'cle' => $cle,
                'nom' => $annonce->lieuNom !== null ? mb_substr($annonce->lieuNom, 0, 255) : null,
                'adresse' => $annonce->lieuAdresse !== null ? mb_substr($annonce->lieuAdresse, 0, 255) : null,
                'ville' => mb_substr(trim(($annonce->lieuCodePostal ?? '').' '.($annonce->lieuVille ?? '')), 0, 255) ?: null,
                'lieu_id' => $lieu->id,
            ]);

            return $lieu;
        });

        $this->memoire["{$source->id}:{$cle}"] = $lieu->id;

        return $lieu;
    }

    private function rattacherOuCreer(AnnonceNormalisee $annonce, Source $source): Lieu
    {
        $ville = $this->villeDeclaree($annonce);
        [$position, $precision] = $this->positionSource($annonce, $ville);

        if ($position === null && filled($annonce->lieuAdresse)) {
            $geocodage = $this->ban->geocoder($annonce->lieuAdresse, $annonce->lieuCodePostal, $annonce->lieuVille, $ville?->code_insee);

            if ($geocodage !== null) {
                [$position, $precision] = [$geocodage['position'], PrecisionPosition::Adresse];
                $ville ??= Ville::firstWhere('code_insee', CodeInsee::commune($geocodage['code_insee']));
            }
        }

        $ville ??= $position ? $this->communeLaPlusProche($position) : null;

        // 2-3. Rapprochement sûr : nom proche à moins de 200 m, ou même adresse dans la même commune.
        $existant = ($position ? $this->procheParNomEtDistance($annonce, $position) : null)
            ?? ($ville ? $this->memeAdresse($annonce, $ville) : null);

        if ($existant !== null) {
            $this->completer($existant, $annonce, $ville, $position, $precision);

            return $existant;
        }

        // Sans position précise : même nom dans la même commune → rattaché, mais à vérifier.
        if ($position === null && $ville !== null && ($homonyme = $this->procheParNomDansCommune($annonce, $ville)) !== null) {
            $this->aVerifier($homonyme, $source, $annonce, 'Rattaché par le nom seulement (position inconnue)', 1);

            return $homonyme;
        }

        // 4. Nouveau lieu.
        if ($position === null) {
            [$position, $precision] = [$ville?->position, PrecisionPosition::Commune];
        }

        // Tronqué aux tailles des colonnes : une donnée anormale d'une source ne doit pas faire échouer la collecte.
        $lieu = Lieu::create([
            'nom' => mb_substr($annonce->lieuNom ?: $annonce->lieuAdresse, 0, 255),
            'type' => TypeLieu::Autre,
            'adresse' => $annonce->lieuAdresse !== null ? mb_substr($annonce->lieuAdresse, 0, 255) : null,
            'code_postal' => $annonce->lieuCodePostal !== null ? mb_substr($annonce->lieuCodePostal, 0, 10) : null,
            'ville_id' => $ville?->id,
            'position' => $position,
            'precision_position' => $precision,
            'fuseau_horaire' => $ville?->fuseau_horaire ?? 'Europe/Paris', // 5. fuseau de la commune
        ]);

        [$motif, $priorite] = match (true) {
            $ville === null => ['Commune introuvable : position inconnue', 3],
            $precision === PrecisionPosition::Commune => ['Position approximative (centre de la commune)', 2],
            default => ['Nouveau lieu, absent du référentiel', 0],
        };

        $this->aVerifier($lieu, $source, $annonce, $motif, $priorite);

        return $lieu;
    }

    /** Même nom, même adresse, même ville : la façon dont la source écrit ce lieu. */
    private function cle(AnnonceNormalisee $annonce): string
    {
        return hash('sha256', implode('|', [
            Texte::normaliser($annonce->lieuNom),
            $this->comparaison->adresseNormalisee($annonce->lieuAdresse),
            $annonce->lieuCodePostal ?: Texte::normaliser($annonce->lieuVille),
        ]));
    }

    /** Commune écrite par la source (nom, aidé du code postal pour les homonymes). */
    private function villeDeclaree(AnnonceNormalisee $annonce): ?Ville
    {
        $codePostal = preg_match('/^\d{5}$/', trim((string) $annonce->lieuCodePostal)) ? trim($annonce->lieuCodePostal) : null;
        $nom = trim(preg_replace('/\b(cedex|\d+ ?(e|er|eme)?|arrondissement)\b/', ' ', Texte::normaliser($annonce->lieuVille)));
        $nom = preg_replace('/\s+/', ' ', $nom);

        $candidates = $nom !== '' ? Ville::where('nom_normalise', $nom)->orderByDesc('population')->get() : collect();

        if ($candidates->count() > 1 && $codePostal !== null) {
            $candidates = $candidates->filter(fn (Ville $v) => in_array($codePostal, $v->codes_postaux ?? [], true))->whenEmpty(
                fn () => $candidates->filter(fn (Ville $v) => str_starts_with($codePostal, $v->departement)),
            )->whenEmpty(fn () => $candidates);
        }

        return $candidates->first()
            ?? ($codePostal ? Ville::whereJsonContains('codes_postaux', $codePostal)->orderByDesc('population')->first() : null);
    }

    /**
     * Position fournie par la source, si elle est plausible : en France, pas 0,0 (Fnac), et pas à l'autre bout du pays
     * par rapport à sa commune (latitude et longitude inversées…).
     *
     * @return array{0: ?Point, 1: ?PrecisionPosition}
     */
    private function positionSource(AnnonceNormalisee $annonce, ?Ville $ville): array
    {
        $lat = $annonce->lieuLatitude;
        $lon = $annonce->lieuLongitude;

        if ($lat === null || $lon === null || ! $this->enFrance($lat, $lon)) {
            return [null, null];
        }

        $position = new Point($lat, $lon);

        if ($ville?->position !== null && $this->distanceKm($position, $ville->position) > self::ECART_COMMUNE_MAX_KM) {
            return [null, null];
        }

        return [$position, PrecisionPosition::Exacte];
    }

    private function enFrance(float $lat, float $lon): bool
    {
        foreach (self::ZONES_FRANCE as [$latMin, $latMax, $lonMin, $lonMax]) {
            if ($lat >= $latMin && $lat <= $latMax && $lon >= $lonMin && $lon <= $lonMax) {
                return true;
            }
        }

        return false;
    }

    private function communeLaPlusProche(Point $position): ?Ville
    {
        return Ville::whereRaw('ST_DWithin(position, ?::geography, 30000)', [$position->versEwkt()])
            ->orderByRaw('ST_Distance(position, ?::geography)', [$position->versEwkt()])
            ->first();
    }

    private function procheParNomEtDistance(AnnonceNormalisee $annonce, Point $position): ?Lieu
    {
        return Lieu::actifs()
            ->whereNotNull('position')
            ->whereRaw('ST_DWithin(position, ?::geography, ?)', [$position->versEwkt(), config('collecte.rapprochement_lieux_metres')])
            ->orderByRaw('ST_Distance(position, ?::geography)', [$position->versEwkt()])
            ->get()
            ->first(fn (Lieu $lieu) => $this->comparaison->nomsProches($lieu->nom, $annonce->lieuNom));
    }

    private function memeAdresse(AnnonceNormalisee $annonce, Ville $ville): ?Lieu
    {
        $adresse = $this->comparaison->adresseNormalisee($annonce->lieuAdresse);

        // Une adresse sans numéro (« place de l'Horloge ») ne suffit pas à reconnaître un lieu.
        if (! preg_match('/^\d/', $adresse)) {
            return null;
        }

        return Lieu::actifs()->where('ville_id', $ville->id)->whereNotNull('adresse')->get()
            ->first(fn (Lieu $lieu) => $this->comparaison->adresseNormalisee($lieu->adresse) === $adresse);
    }

    private function procheParNomDansCommune(AnnonceNormalisee $annonce, Ville $ville): ?Lieu
    {
        $proches = Lieu::actifs()->where('ville_id', $ville->id)->get()
            ->filter(fn (Lieu $lieu) => $this->comparaison->nomsProches($lieu->nom, $annonce->lieuNom));

        return $proches->count() === 1 ? $proches->first() : null; // plusieurs candidats : on ne choisit pas au hasard
    }

    /** Un lieu reconnu à coup sûr récupère ce qui lui manquait (jamais un champ corrigé à la main). */
    private function completer(Lieu $lieu, AnnonceNormalisee $annonce, ?Ville $ville, ?Point $position, ?PrecisionPosition $precision): void
    {
        $valeurs = array_filter([
            // Un nom fabriqué avec l'adresse cède la place au vrai nom dès qu'une source le donne.
            'nom' => $lieu->nomFabrique() && filled($annonce->lieuNom) ? mb_substr($annonce->lieuNom, 0, 255) : null,
            'adresse' => blank($lieu->adresse) && $annonce->lieuAdresse !== null ? mb_substr($annonce->lieuAdresse, 0, 255) : null,
            'code_postal' => blank($lieu->code_postal) && $annonce->lieuCodePostal !== null ? mb_substr($annonce->lieuCodePostal, 0, 10) : null,
            'ville_id' => $lieu->ville_id === null ? $ville?->id : null,
        ]);

        if ($position !== null && ($lieu->position === null || $this->rang($precision) > $this->rang($lieu->precision_position))) {
            $valeurs += ['position' => $position, 'precision_position' => $precision];
        }

        $valeurs = array_filter($valeurs, fn ($valeur, string $champ) => ! $lieu->estVerrouille($champ), ARRAY_FILTER_USE_BOTH);

        if ($valeurs !== []) {
            $lieu->update($valeurs);
        }
    }

    /** Lieu déjà connu de la source : s'il ne porte que son adresse et que l'annonce donne un nom, il prend ce nom. */
    private function nommer(Lieu $lieu, AnnonceNormalisee $annonce): Lieu
    {
        if ($lieu->nomFabrique() && filled($annonce->lieuNom)) {
            $lieu->update(['nom' => mb_substr($annonce->lieuNom, 0, 255)]);
        }

        return $lieu;
    }

    private function rang(?PrecisionPosition $precision): int
    {
        return match ($precision) {
            PrecisionPosition::Exacte => 3,
            PrecisionPosition::Adresse => 2,
            PrecisionPosition::Commune => 1,
            null => 0,
        };
    }

    /** Ajoute le lieu à la file « Lieux à vérifier » (une seule fois par lieu). */
    private function aVerifier(Lieu $lieu, Source $source, AnnonceNormalisee $annonce, string $motif, int $priorite): void
    {
        ElementATraiter::firstOrCreate([
            'file' => FileATraiter::LieuAVerifier,
            'cible_type' => $lieu->getMorphClass(),
            'cible_id' => $lieu->id,
        ], [
            'priorite' => $priorite,
            'donnees' => [
                'motif' => $motif,
                'source' => $source->nom,
                'nom_source' => $annonce->lieuNom,
                'adresse_source' => $annonce->lieuAdresse,
                'ville_source' => trim(($annonce->lieuCodePostal ?? '').' '.($annonce->lieuVille ?? '')) ?: null,
                'annonce' => $annonce->titre,
            ],
        ]);
    }

    /** Suit les fusions jusqu'au lieu conservé. */
    private function lieuFinal(Lieu $lieu): Lieu
    {
        for ($i = 0; $i < 10 && $lieu->fusionne_dans_id !== null; $i++) {
            $lieu = Lieu::findOrFail($lieu->fusionne_dans_id);
        }

        return $lieu;
    }

    private function distanceKm(Point $a, Point $b): float
    {
        $dLat = deg2rad($b->latitude - $a->latitude);
        $dLon = deg2rad($b->longitude - $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->latitude)) * cos(deg2rad($b->latitude)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }
}
