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
 * « Que faire à Paris ? » (open data Ville de Paris, API OpenDataSoft, sans clé, licence ODbL, COLLECTE §3).
 * Événements à venir (≈ 3 600). Chaque horaire (`occurrences`) est une séance ; sans horaire : jour isolé
 * « horaire à confirmer », plusieurs jours → une période (décision du 06/10/2026). Version : date de mise à jour du jeu.
 */
class ConnecteurParisQfap implements Connecteur, DetecteVersion
{
    private const JEU = 'https://opendata.paris.fr/api/explore/v2.1/catalog/datasets/que-faire-a-paris-';

    private const FUSEAU = 'Europe/Paris';

    public function versionDisponible(Source $source): ?string
    {
        $reponse = Http::timeout(30)->retry(2, 2000, throw: false)->get(self::JEU);

        if (! $reponse->successful()) {
            throw new RuntimeException("Que faire à Paris : fiche du jeu inaccessible ({$reponse->status()}).");
        }

        return $reponse->json('metas.default.data_processed') ?? $reponse->json('metas.default.modified');
    }

    public function telecharger(Source $source): string
    {
        $reponse = Http::timeout(300)->retry(2, 5000, throw: false)->get(self::JEU.'/exports/json', [
            'where' => "date_end >= date'".CarbonImmutable::today(self::FUSEAU)->format('Y-m-d')."'",
            'timezone' => self::FUSEAU,
        ]);

        if (! $reponse->successful()) {
            throw new RuntimeException("Téléchargement de Que faire à Paris impossible ({$reponse->status()}).");
        }

        return $reponse->body();
    }

    public function extensionBrut(): string
    {
        return 'json';
    }

    public function lire(string $contenuBrut, Source $source): iterable
    {
        foreach (json_decode($contenuBrut, true) ?? [] as $evenement) {
            try {
                foreach ($this->annonces($evenement) as $annonce) {
                    yield $annonce;
                }
            } catch (InvalidArgumentException|Throwable $erreur) {
                yield new LigneIllisible('Événement '.($evenement['id'] ?? '?').' : '.$erreur->getMessage(), $evenement['id'] ?? null);
            }
        }
    }

    /** @return iterable<AnnonceNormalisee> */
    private function annonces(array $e): iterable
    {
        $id = (string) $e['id'];
        $aujourdhui = CarbonImmutable::today(self::FUSEAU);
        $commun = [
            'identifiantSpectacle' => $id,
            'titre' => trim(html_entity_decode((string) ($e['title'] ?? ''), ENT_QUOTES | ENT_HTML5)),
            'lien' => (string) (($e['access_link'] ?? null) ?: ($e['url'] ?? null) ?: 'https://quefaire.paris.fr/'.$id), // réservation d'abord
            'lieuNom' => trim((string) ($e['address_name'] ?? '')) ?: null,
            'lieuAdresse' => trim((string) ($e['address_street'] ?? '')) ?: null,
            'lieuCodePostal' => preg_match('/\b\d{5}\b/', (string) ($e['address_zipcode'] ?? ''), $cp) ? $cp[0] : null,
            'lieuVille' => trim((string) ($e['address_city'] ?? '')) ?: 'Paris',
            'lieuLatitude' => is_numeric($e['lat_lon']['lat'] ?? null) ? (float) $e['lat_lon']['lat'] : null,
            'lieuLongitude' => is_numeric($e['lat_lon']['lon'] ?? null) ? (float) $e['lat_lon']['lon'] : null,
            'categoriesSource' => array_values(array_filter(array_map('trim', explode(';', (string) ($e['qfap_tags'] ?? ''))))),
            'description' => $this->texte($e['lead_text'] ?? null) ?? $this->texte($e['description'] ?? null),
            'imageUrl' => $e['cover_url'] ?? null,
            ...$this->prix($e),
            'misAJourSource' => isset($e['updated_at']) ? CarbonImmutable::parse($e['updated_at']) : null,
        ];

        // 1. Horaires détaillés : « début_fin;début_fin;… ».
        $horaires = array_filter(explode(';', (string) ($e['occurrences'] ?? '')));

        foreach ($horaires as $horaire) {
            [$debut] = explode('_', $horaire, 2);
            $debut = CarbonImmutable::parse($debut)->setTimezone(self::FUSEAU);

            if ($debut->gte($aujourdhui)) {
                yield new AnnonceNormalisee(...[...$commun, 'identifiantExterne' => $id.'@'.$debut->format('Y-m-d\TH:i'), 'debut' => $debut, 'heureConnue' => $debut->format('H:i') !== '00:00']);
            }
        }

        if ($horaires !== [] || blank($e['date_start'] ?? null)) {
            return;
        }

        // 2. Sans horaire : un jour → « horaire à confirmer » ; plusieurs jours → une période.
        $debut = CarbonImmutable::parse($e['date_start'])->setTimezone(self::FUSEAU)->startOfDay();
        $fin = CarbonImmutable::parse($e['date_end'] ?? $e['date_start'])->setTimezone(self::FUSEAU)->startOfDay();

        if ($fin->lt($aujourdhui)) {
            return;
        }

        $cle = $debut->format('Y-m-d').($fin->gt($debut) ? '..'.$fin->format('Y-m-d') : '');
        yield new AnnonceNormalisee(...[...$commun, 'identifiantExterne' => "{$id}@{$cle}", 'debut' => $debut, 'heureConnue' => false, 'fin' => $fin->gt($debut) ? $fin : null]);
    }

    /** @return array{prixMin: ?float, prixMax: ?float, gratuit: bool} */
    private function prix(array $e): array
    {
        $texte = html_entity_decode(strip_tags((string) ($e['price_detail'] ?? '')), ENT_QUOTES | ENT_HTML5);
        preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:€|EUR|euros?)/iu', $texte, $montants);
        preg_match_all('/de\s+(\d+(?:[.,]\d+)?)\s*(?:€|euros?)?\s*à\s*\d+(?:[.,]\d+)?\s*(?:€|EUR|euros?)/iu', $texte, $fourchettes); // « De 8 à 20 euros »
        $prix = array_values(array_filter(
            array_map(fn ($m) => (float) str_replace(',', '.', $m), [...$montants[1], ...$fourchettes[1]]),
            fn (float $p) => $p > 0,
        ));

        return [
            'prixMin' => $prix === [] ? null : min($prix),
            'prixMax' => $prix === [] ? null : max($prix),
            'gratuit' => ($e['price_type'] ?? '') === 'gratuit',
        ];
    }

    private function texte(?string $html): ?string
    {
        $texte = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br />', '<br>'], "\n", (string) $html)), ENT_QUOTES | ENT_HTML5));

        return $texte === '' ? null : $texte;
    }
}
