<?php

namespace App\Collecte\Connecteurs;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\CollecteParIntervalle;
use App\Collecte\Connecteur;
use App\Collecte\LigneIllisible;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * DATAtourisme par son API REST (api.datatourisme.fr, clé reçue le 06/10/2026, étape N03b) : contrairement à l'export
 * data.gouv.fr, elle donne l'heure des séances (≈ 9 sur 10), le prix et souvent le lien de réservation.
 * On ne demande que les événements de spectacle à venir (≈ 18 000, ≈ 180 requêtes pour 1 000 autorisées par heure).
 * Pour chaque créneau :
 * - avec heure : une séance par jour du créneau (jusqu'à 31 jours ; au-delà, une période) ;
 * - sans heure : un jour isolé → « horaire à confirmer » ; plusieurs jours → une seule « période » (décision du 06/10).
 * L'API n'annonce pas de version : une collecte par jour, tôt le matin.
 */
class ConnecteurDatatourisme implements CollecteParIntervalle, Connecteur
{
    private const FUSEAU = 'Europe/Paris';

    private const SEANCES_QUOTIDIENNES_MAX_JOURS = 31;

    /** Types de l'ontologie qui relèvent du spectacle vivant (ceux demandés à l'API). */
    private const TYPES_SPECTACLE = ['ShowEvent', 'TheaterEvent', 'Concert', 'DanceEvent', 'CircusEvent', 'Opera', 'ComedyEvent', 'Recital',
        'StreetArtShow', 'PuppetShow', 'Festival', 'MusicEvent'];

    private const CHAMPS = 'uuid,label,type,takesPlaceAt,offers,hasBookingContact,hasContact,isLocatedAt,hasDescription,hasMainRepresentation,lastUpdate';

    public function intervalleHeures(): int
    {
        return 24;
    }

    /** @return array{0: int, 1: int} */
    public function plageHoraire(): array
    {
        return [5, 9];
    }

    public function extensionBrut(): string
    {
        return 'json';
    }

    /** Toutes les pages de spectacles à venir, conservées telles quelles (une page par ligne). */
    public function telecharger(Source $source): string
    {
        $cle = config('services.datatourisme.cle');

        if (blank($cle)) {
            throw new RuntimeException('Clé de l’API DATAtourisme absente (DATATOURISME_API_KEY).');
        }

        $adresse = config('services.datatourisme.adresse').'/entertainmentAndEvent?'.http_build_query([
            'filters' => 'type[in]='.implode(',', self::TYPES_SPECTACLE).' AND takesPlaceAt.endDate[gte]='.CarbonImmutable::today(self::FUSEAU)->format('Y-m-d'),
            'page_size' => 100,
            'lang' => 'fr',
            'fields' => self::CHAMPS,
        ]);
        $pages = [];

        while ($adresse !== null) {
            $reponse = Http::withHeaders(['X-API-Key' => $cle])->timeout(60)->retry(3, 3000, throw: false)->get($adresse);

            if (! $reponse->successful()) {
                throw new RuntimeException("API DATAtourisme : erreur {$reponse->status()} (page ".(count($pages) + 1).').');
            }

            $pages[] = json_encode($reponse->json('objects', []), JSON_UNESCAPED_UNICODE);
            $adresse = $reponse->json('meta.next');
        }

        return implode("\n", $pages);
    }

    public function lire(string $contenuBrut, Source $source): iterable
    {
        foreach (explode("\n", $contenuBrut) as $page) {
            foreach (json_decode($page, true) ?? [] as $evenement) {
                try {
                    foreach ($this->annonces($evenement) as $annonce) {
                        yield $annonce;
                    }
                } catch (InvalidArgumentException|Throwable $erreur) {
                    yield new LigneIllisible('Événement '.($evenement['uuid'] ?? '?').' : '.$erreur->getMessage(), $evenement['uuid'] ?? null);
                }
            }
        }
    }

    /** @return iterable<AnnonceNormalisee> */
    private function annonces(array $evenement): iterable
    {
        $uuid = (string) $evenement['uuid'];
        $aujourdhui = CarbonImmutable::today(self::FUSEAU);
        $commun = [
            'identifiantSpectacle' => $uuid,
            'titre' => trim((string) ($evenement['label']['@fr'] ?? reset($evenement['label']) ?: '')),
            'lien' => $this->lien($evenement) ?? 'https://data.datatourisme.fr/'.$uuid,
            ...$this->lieu($evenement),
            'categoriesSource' => $this->types($evenement['type'] ?? []),
            'description' => $this->description($evenement),
            'imageUrl' => $evenement['hasMainRepresentation'][0]['hasRelatedResource'][0]['locator'][0] ?? null,
            ...$this->prix($evenement),
            'misAJourSource' => isset($evenement['lastUpdate']) ? CarbonImmutable::parse($evenement['lastUpdate'], self::FUSEAU) : null,
        ];
        $deja = [];

        foreach ($evenement['takesPlaceAt'] ?? [] as $creneau) {
            $debut = CarbonImmutable::parse($creneau['startDate'], self::FUSEAU)->startOfDay();
            $fin = CarbonImmutable::parse($creneau['endDate'] ?? $creneau['startDate'], self::FUSEAU)->startOfDay();
            $heure = $creneau['startTime'] ?? null;

            if ($fin->lt($aujourdhui)) {
                continue;
            }

            $jours = $debut->diffInDays($fin) + 1;

            if ($heure !== null && $jours <= self::SEANCES_QUOTIDIENNES_MAX_JOURS) {
                // Une séance par jour du créneau, à l'heure donnée.
                for ($jour = $debut->max($aujourdhui); $jour->lte($fin); $jour = $jour->addDay()) {
                    $seance = CarbonImmutable::parse($jour->format('Y-m-d').' '.$heure, self::FUSEAU);
                    $cle = $seance->format('Y-m-d\TH:i');
                    if (! isset($deja[$cle])) {
                        $deja[$cle] = true;
                        yield new AnnonceNormalisee(...[...$commun, 'identifiantExterne' => "{$uuid}@{$cle}", 'debut' => $seance, 'heureConnue' => true]);
                    }
                }

                continue;
            }

            $cle = $debut->format('Y-m-d').($fin->gt($debut) ? '..'.$fin->format('Y-m-d') : '');
            if (! isset($deja[$cle])) {
                $deja[$cle] = true;
                yield new AnnonceNormalisee(...[...$commun, 'identifiantExterne' => "{$uuid}@{$cle}", 'debut' => $debut, 'heureConnue' => false, 'fin' => $fin->gt($debut) ? $fin : null]);
            }
        }
    }

    /**
     * Lieu : la première ligne de l'adresse est souvent le nom de la salle (« TMP - Théâtre Municipal Pazenais »,
     * puis « 7 rue du Ballon »).
     */
    private function lieu(array $evenement): array
    {
        $lieu = $evenement['isLocatedAt'][0] ?? [];
        $adresse = $lieu['address'][0] ?? [];
        $lignes = array_values(array_filter(array_map('trim', (array) ($adresse['streetAddress'] ?? []))));
        $nom = null;

        if (count($lignes) >= 2 && ! preg_match('/^\d/', $lignes[0])) {
            $nom = array_shift($lignes);
        } elseif (count($lignes) === 1 && ! preg_match('/\d/', $lignes[0])) {
            $nom = array_shift($lignes); // une seule ligne sans numéro : un nom de salle (« Cinéma Le Doron »)
        }

        $ville = $adresse['addressLocality'] ?? ($adresse['hasAddressCity']['label']['@fr'] ?? null);

        return [
            'lieuNom' => $nom,
            'lieuAdresse' => $lignes !== [] ? implode(', ', $lignes) : ($nom === null ? $ville : null),
            'lieuCodePostal' => preg_match('/\b\d{5}\b/', (string) ($adresse['postalCode'] ?? ''), $cp) ? $cp[0] : null,
            'lieuVille' => $ville,
            'lieuLatitude' => is_numeric($lieu['geo']['latitude'] ?? null) ? (float) $lieu['geo']['latitude'] : null,
            'lieuLongitude' => is_numeric($lieu['geo']['longitude'] ?? null) ? (float) $lieu['geo']['longitude'] : null,
        ];
    }

    /** Lien de réservation, sinon site de l'organisateur. */
    private function lien(array $evenement): ?string
    {
        foreach ([$evenement['hasBookingContact'] ?? [], $evenement['hasContact'] ?? []] as $contacts) {
            foreach ((array) $contacts as $contact) {
                foreach ((array) ($contact['homepage'] ?? []) as $site) {
                    if (is_string($site) && str_starts_with($site, 'http')) {
                        return $site;
                    }
                }
            }
        }

        return null;
    }

    /** @return array{prixMin: ?float, prixMax: ?float, gratuit: bool} */
    private function prix(array $evenement): array
    {
        $prix = [];
        $gratuit = false;

        foreach ($evenement['offers'] ?? [] as $offre) {
            foreach ($offre['priceSpecification'] ?? [] as $tarif) {
                foreach (['minPrice', 'maxPrice'] as $champ) {
                    foreach ((array) ($tarif[$champ] ?? []) as $valeur) {
                        if (is_numeric($valeur)) {
                            $prix[] = (float) $valeur;
                        }
                    }
                }

                // Le minimum n'est parfois que dans le texte : « De 8€ à 16€ ».
                $texte = implode(' ', array_filter((array) ($tarif['additionalInformation']['@fr'] ?? []), 'is_string')); // parfois une liste
                preg_match_all('/(\d+(?:[.,]\d+)?)\s*€/u', $texte, $montants);
                foreach ($montants[1] as $montant) {
                    $prix[] = (float) str_replace(',', '.', $montant);
                }
                $gratuit = $gratuit || str_contains(mb_strtolower(json_encode($tarif, JSON_UNESCAPED_UNICODE)), 'gratuit');
            }
        }

        $payants = array_values(array_filter($prix, fn (float $p) => $p > 0));

        return [
            'prixMin' => $payants === [] ? null : min($payants),
            'prixMax' => $payants === [] ? null : max($payants),
            'gratuit' => $payants === [] && ($gratuit || $prix !== []),
        ];
    }

    private function description(array $evenement): ?string
    {
        $texte = $evenement['hasDescription'][0]['shortDescription']['@fr'] ?? $evenement['hasDescription'][0]['description']['@fr'] ?? null;
        $texte = $texte !== null ? trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $texte)), ENT_QUOTES | ENT_HTML5)) : null;

        return $texte ?: null;
    }

    /** Types transmis au tri : les types de spectacle (et ChildrensEvent pour le jeune public), comme en N03. */
    private function types(array $types): array
    {
        $types = array_values(array_unique(array_map(fn ($t) => (string) preg_replace('/^.*[#\/]/', '', (string) $t), $types)));

        return array_values(array_filter($types, fn (string $t) => in_array($t, [...self::TYPES_SPECTACLE, 'ChildrensEvent'], true)));
    }
}
