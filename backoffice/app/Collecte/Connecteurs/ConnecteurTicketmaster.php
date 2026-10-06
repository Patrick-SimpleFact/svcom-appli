<?php

namespace App\Collecte\Connecteurs;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteur;
use App\Collecte\DetecteVersion;
use App\Collecte\LigneIllisible;
use App\Collecte\ObjetsJsonEnFlux;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Ticketmaster par son flux national « Discovery Feed » (COLLECTE §3) : un seul fichier compressé avec tous les
 * événements de France (≈ 71 000, 34 Mo compressés, ≈ 500 Mo décompressés, lu objet par objet).
 * Version : l'adresse du flux redirige vers un fichier daté (EVENTS_RAW-FR-…-2026-10-06_142710.json.gz).
 * Un événement = une séance ; annulé → pas publié. Le flux ne donne pas les prix.
 */
class ConnecteurTicketmaster implements Connecteur, DetecteVersion
{
    /** Valeurs de classification sans information. */
    private const SANS_INFORMATION = ['Undefined', 'Indéfini', 'Other', 'Autre'];

    public function versionDisponible(Source $source): ?string
    {
        $reponse = Http::withoutRedirecting()->timeout(30)->retry(2, 2000, throw: false)->get($this->adresseDuFlux($source));
        $fichier = $reponse->header('Location');

        if ($reponse->status() < 300 || $reponse->status() >= 400 || $fichier === '') {
            throw new RuntimeException("Flux Ticketmaster : pas de redirection vers un fichier ({$reponse->status()}).");
        }

        return basename(parse_url($fichier, PHP_URL_PATH));
    }

    public function telecharger(Source $source): string
    {
        $reponse = Http::timeout(600)->retry(2, 5000, throw: false)->get($this->adresseDuFlux($source));

        if (! $reponse->successful()) {
            throw new RuntimeException("Téléchargement du flux Ticketmaster impossible ({$reponse->status()}).");
        }

        return $reponse->body();
    }

    public function extensionBrut(): string
    {
        return 'json.gz';
    }

    public function lire(string $contenuBrut, Source $source): iterable
    {
        $fichier = tempnam(sys_get_temp_dir(), 'tm');
        file_put_contents($fichier, $contenuBrut);

        try {
            $flux = str_starts_with($contenuBrut, "\x1f\x8b") ? gzopen($fichier, 'rb') : fopen($fichier, 'rb');

            foreach (ObjetsJsonEnFlux::objets($flux) as $texte) {
                $evenement = json_decode($texte, true);

                if (! is_array($evenement)) {
                    yield new LigneIllisible('Événement illisible (JSON).');

                    continue;
                }

                try {
                    $annonce = $this->annonce($evenement);
                    if ($annonce !== null) {
                        yield $annonce;
                    }
                } catch (InvalidArgumentException|Throwable $erreur) {
                    yield new LigneIllisible('Événement '.($evenement['eventId'] ?? '?').' : '.$erreur->getMessage(), $evenement['eventId'] ?? null);
                }
            }
        } finally {
            if (isset($flux) && is_resource($flux)) {
                fclose($flux);
            }
            @unlink($fichier);
        }
    }

    private function annonce(array $e): ?AnnonceNormalisee
    {
        if (($e['eventStatus'] ?? '') === 'cancelled' || blank($e['eventStartLocalDate'] ?? null)) {
            return null;
        }

        $lieu = $e['venue'] ?? [];
        $fuseau = $lieu['venueTimezone'] ?? 'Europe/Paris';

        if (($lieu['venueCountryCode'] ?? 'FR') !== 'FR') {
            return null;
        }

        $heure = $e['eventStartLocalTime'] ?? null;
        $debut = CarbonImmutable::parse($e['eventStartLocalDate'].' '.($heure ?: '00:00'), $fuseau);

        if ($debut->lt(CarbonImmutable::today($fuseau))) {
            return null;
        }

        $artistes = collect($e['attractions'] ?? [])->map(fn ($a) => $a['attraction']['attractionName'] ?? null)->filter()->values()->all();

        return new AnnonceNormalisee(
            identifiantExterne: (string) $e['eventId'],
            titre: trim((string) $e['eventName']),
            debut: $debut,
            heureConnue: filled($heure),
            lien: (string) ($e['primaryEventUrl'] ?? ''),
            lieuNom: trim((string) ($lieu['venueName'] ?? '')) ?: null,
            lieuAdresse: trim((string) ($lieu['venueStreet'] ?? '')) ?: null,
            lieuCodePostal: trim((string) ($lieu['venueZipCode'] ?? '')) ?: null,
            lieuVille: trim((string) ($lieu['venueCity'] ?? '')) ?: null,
            lieuLatitude: is_numeric($lieu['venueLatitude'] ?? null) ? (float) $lieu['venueLatitude'] : null,
            lieuLongitude: is_numeric($lieu['venueLongitude'] ?? null) ? (float) $lieu['venueLongitude'] : null,
            categoriesSource: array_values(array_unique(array_filter(
                [$e['classificationSegment'] ?? null, $e['classificationGenre'] ?? null, $e['classificationSubGenre'] ?? null],
                fn ($c) => is_string($c) && $c !== '' && ! in_array($c, self::SANS_INFORMATION, true),
            ))),
            description: trim(strip_tags((string) ($e['eventInfo'] ?? ''))) ?: null,
            imageUrl: $e['eventImageUrl'] ?? null,
            artistes: $artistes,
            // Un même spectacle dans une même salle : une seule ligne « À trier » pour toutes ses séances.
            identifiantSpectacle: md5(mb_strtolower(trim((string) $e['eventName'])).'|'.($lieu['venueId'] ?? '')),
        );
    }

    private function adresseDuFlux(Source $source): string
    {
        $cle = config('services.ticketmaster.cle');

        if (blank($cle)) {
            throw new RuntimeException('Clé Ticketmaster absente (TICKETMASTER_CONSUMER_KEY).');
        }

        return config('services.ticketmaster.flux').'?'.http_build_query(['apikey' => $cle, 'countryCode' => $source->config['pays'] ?? 'FR']);
    }
}
