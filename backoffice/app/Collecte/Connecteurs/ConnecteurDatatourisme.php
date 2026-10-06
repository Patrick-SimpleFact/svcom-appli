<?php

namespace App\Collecte\Connecteurs;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteur;
use App\Collecte\DetecteVersion;
use App\Collecte\LigneIllisible;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * DATAtourisme (export national des « fêtes et manifestations » publié chaque nuit sur data.gouv.fr, Licence Ouverte,
 * sans compte, COLLECTE §3). Pas d'horaires, seulement des périodes de dates :
 * - un jour isolé → une annonce du jour (« horaire à confirmer ») ;
 * - plusieurs jours → une seule annonce « période » (du … au …, décision de Patrick du 06/10/2026), quelle que soit
 *   sa durée : une période ne prend qu'une ligne (Patrick, 06/10 : les périodes de plus d'un an sont publiées aussi).
 * L'adresse du fichier change chaque jour : on la retrouve par l'API data.gouv.fr.
 */
class ConnecteurDatatourisme implements Connecteur, DetecteVersion
{
    public const JEU_DE_DONNEES = 'https://www.data.gouv.fr/api/1/datasets/5b598be088ee387c0c353714/';

    private const RESSOURCE = 'datatourisme-fma.csv';

    private const FUSEAU = 'Europe/Paris';

    /** Types de l'ontologie qui relèvent du spectacle vivant (POC). */
    private const TYPES_SPECTACLE = ['ShowEvent', 'TheaterEvent', 'Concert', 'DanceEvent', 'CircusEvent', 'Opera', 'ComedyEvent', 'Recital',
        'StreetArtShow', 'PuppetShow', 'Festival', 'MusicEvent'];

    /** Types de l'ontologie trop généraux pour dire quoi que ce soit du genre. */
    private const TYPES_GENERIQUES = ['Event', 'EntertainmentAndEvent', 'PointOfInterest', 'CulturalEvent', 'Product', 'PlaceOfInterest'];

    public function versionDisponible(Source $source): ?string
    {
        return $this->ressource()['last_modified'] ?? null;
    }

    public function telecharger(Source $source): string
    {
        $reponse = Http::timeout(600)->retry(2, 5000, throw: false)->get($this->ressource()['url']);

        if (! $reponse->successful()) {
            throw new RuntimeException("Téléchargement de l'export DATAtourisme impossible ({$reponse->status()}).");
        }

        return $reponse->body();
    }

    public function extensionBrut(): string
    {
        return 'csv';
    }

    public function lire(string $contenuBrut, Source $source): iterable
    {
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, $contenuBrut);
        rewind($flux);

        $entetes = fgetcsv($flux, separator: ',', enclosure: '"', escape: '');
        $entetes[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $entetes[0]);
        $numero = 1;

        while (($valeurs = fgetcsv($flux, separator: ',', enclosure: '"', escape: '')) !== false) {
            $numero++;

            if ($valeurs === [null]) {
                continue;
            }

            if (count($valeurs) !== count($entetes)) {
                yield new LigneIllisible("Ligne {$numero} : nombre de colonnes incorrect.");

                continue;
            }

            $ligne = array_combine($entetes, $valeurs);

            try {
                foreach ($this->annonces($ligne) as $annonce) {
                    yield $annonce;
                }
            } catch (InvalidArgumentException|Throwable $erreur) {
                yield new LigneIllisible("Ligne {$numero} : ".$erreur->getMessage(), $ligne['URI_ID_du_POI'] ?? null);
            }
        }

        fclose($flux);
    }

    /** @return iterable<AnnonceNormalisee> une par jour isolé ou par période */
    private function annonces(array $ligne): iterable
    {
        $uri = trim($ligne['URI_ID_du_POI'] ?? '');
        $identifiant = basename($uri);
        [$codePostal, $commune] = array_pad(explode('#', $ligne['Code_postal_et_commune'] ?? '', 2), 2, '');
        $aujourdhui = CarbonImmutable::today(self::FUSEAU);
        preg_match('/https?:\/\/[^\s<>#|]+/', $ligne['Contacts_du_POI'] ?? '', $lien);

        $commun = [
            'identifiantSpectacle' => $identifiant,
            'titre' => trim($ligne['Nom_du_POI'] ?? ''),
            'heureConnue' => false,
            'lien' => $lien[0] ?? $uri, // « Plus d'infos » : site de l'organisateur, sinon la fiche DATAtourisme
            'lieuNom' => null,
            'lieuAdresse' => trim($ligne['Adresse_postale'] ?? '') ?: null,
            'lieuCodePostal' => preg_match('/\b\d{5}\b/', $codePostal, $cp) ? $cp[0] : null, // parfois plusieurs codes ou du texte
            'lieuVille' => trim($commune) ?: null,
            'lieuLatitude' => is_numeric($ligne['Latitude'] ?? null) ? (float) $ligne['Latitude'] : null,
            'lieuLongitude' => is_numeric($ligne['Longitude'] ?? null) ? (float) $ligne['Longitude'] : null,
            'categoriesSource' => $this->types($ligne['Categories_de_POI'] ?? ''),
            'description' => trim(strip_tags($ligne['Description'] ?? '')) ?: null,
            'misAJourSource' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $ligne['Date_de_mise_a_jour'] ?? '') ? CarbonImmutable::parse($ligne['Date_de_mise_a_jour'], self::FUSEAU) : null,
        ];

        // Sans adresse, le lieu est la commune elle-même.
        $commun['lieuAdresse'] ??= $commun['lieuVille'];

        $deja = [];

        foreach (array_filter(explode('|', $ligne['Periodes_regroupees'] ?? '')) as $periode) {
            [$debut, $fin] = array_pad(explode('<->', $periode, 2), 2, '');

            try {
                $debut = CarbonImmutable::parse($debut, self::FUSEAU)->startOfDay();
                $fin = $fin !== '' ? CarbonImmutable::parse($fin, self::FUSEAU)->startOfDay() : $debut;
            } catch (Throwable) {
                continue;
            }

            if ($fin->lt($aujourdhui)) {
                continue; // passée
            }

            $cle = $debut->format('Y-m-d').($fin->gt($debut) ? '..'.$fin->format('Y-m-d') : '');

            if (isset($deja[$cle])) {
                continue; // périodes en double dans le même événement
            }

            $deja[$cle] = true;

            yield new AnnonceNormalisee(...[
                ...$commun,
                'identifiantExterne' => $identifiant.'@'.$cle,
                'debut' => $debut,
                'fin' => $fin->gt($debut) ? $fin : null,
            ]);
        }
    }

    /**
     * « https://www.datatourisme.fr/ontology/core#TheaterEvent|… » → ['TheaterEvent', …] sans les types trop généraux.
     * Les types sont souvent cumulés à tort (« Magic Show » : TheaterEvent + SportsEvent) : dès qu'un type de spectacle
     * est présent, seuls les types de spectacle (et ChildrensEvent, pour le jeune public) sont transmis au tri.
     */
    private function types(string $categories): array
    {
        $types = array_map(fn (string $t) => (string) preg_replace('/^.*[#\/]/', '', trim($t)), explode('|', $categories));
        $types = array_values(array_unique(array_filter($types, fn (string $t) => $t !== '' && ! in_array($t, self::TYPES_GENERIQUES, true))));

        if (array_intersect($types, self::TYPES_SPECTACLE) !== []) {
            $types = array_values(array_filter($types, fn (string $t) => in_array($t, [...self::TYPES_SPECTACLE, 'ChildrensEvent'], true)));
        }

        return $types;
    }

    /** La ressource « datatourisme-fma.csv » de la fiche data.gouv.fr (adresse du jour et date de mise à jour). */
    private function ressource(): array
    {
        $reponse = Http::timeout(60)->retry(2, 2000, throw: false)->get(self::JEU_DE_DONNEES);

        if (! $reponse->successful()) {
            throw new RuntimeException("Fiche DATAtourisme inaccessible sur data.gouv.fr ({$reponse->status()}).");
        }

        $ressource = collect($reponse->json('resources', []))->firstWhere('title', self::RESSOURCE);

        if ($ressource === null) {
            throw new RuntimeException('Ressource « '.self::RESSOURCE.' » introuvable sur data.gouv.fr.');
        }

        return $ressource;
    }
}
