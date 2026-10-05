<?php

namespace App\Collecte\Connecteurs;

use App\Collecte\AnnonceNormalisee;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * BilletRéduc (Awin, flux n°47175, COLLECTE §3) : une ligne = un spectacle dans un lieu ; ses séances sont dans
 * `custom_3` (liste JSON : date, complet, reporté). Chaque séance à venir devient une annonce.
 * Source n°1 du théâtre et de l'humour ; donne le « complet » et les coordonnées.
 */
class ConnecteurBilletReduc extends ConnecteurAwin
{
    /** Heures du flux : heure de Paris (BilletRéduc ne vend qu'en métropole). */
    private const FUSEAU = 'Europe/Paris';

    protected function annonces(array $ligne): iterable
    {
        $produit = trim($ligne['merchant_product_id'] ?? '');
        $seances = json_decode($ligne['custom_3'] ?? '', true);

        if (! is_array($seances)) {
            throw new InvalidArgumentException('séances illisibles (custom_3).');
        }

        $aujourdhui = CarbonImmutable::today(self::FUSEAU);
        $commun = $this->communes($ligne, $produit);

        foreach ($seances as $seance) {
            $date = isset($seance['SessionDate']) ? CarbonImmutable::parse($seance['SessionDate'], self::FUSEAU) : null;

            if ($date === null || $date->lt($aujourdhui)) {
                continue; // séance passée : rien à publier
            }

            yield new AnnonceNormalisee(...[
                ...$commun,
                'identifiantExterne' => $produit.'@'.$date->format('Y-m-d\TH:i'),
                'debut' => $date,
                'heureConnue' => $date->format('H:i') !== '00:00',
                'complet' => (bool) ($seance['SoldOut'] ?? false),
            ]);
        }
    }

    /** Ce qui est commun à toutes les séances du spectacle. */
    private function communes(array $ligne, string $produit): array
    {
        $prix = array_filter([
            $this->nombre($ligne['Tickets:min_price'] ?? null),
            $this->nombre($ligne['search_price'] ?? null),
            $this->nombre($ligne['product_price_old'] ?? null),
        ]);
        $description = collect([$this->texte($ligne['description'] ?? null), $this->texte($ligne['product_short_description'] ?? null)])
            ->filter()->unique()->implode("\n");

        return [
            'identifiantSpectacle' => $produit,
            'titre' => trim($ligne['product_name'] ?? ''),
            'lien' => $ligne['aw_deep_link'] ?? '',
            'lieuNom' => trim($ligne['Tickets:venue_name'] ?? '') ?: null,
            'lieuAdresse' => trim($ligne['Tickets:event_location_address'] ?? '') ?: null,
            'lieuCodePostal' => trim($ligne['custom_1'] ?? '') ?: null,
            'lieuVille' => trim($ligne['custom_2'] ?? '') ?: (trim($ligne['Tickets:event_location_city'] ?? '') ?: null),
            'lieuLatitude' => $this->nombre($ligne['Tickets:latitude'] ?? null),
            'lieuLongitude' => $this->nombre($ligne['Tickets:longitude'] ?? null),
            'categoriesSource' => array_values(array_filter(array_map('trim', explode('|', $ligne['merchant_product_category_path'] ?? '')))),
            'description' => $description ?: null,
            'imageUrl' => trim($ligne['merchant_image_url'] ?? '') ?: null,
            'artistes' => array_values(array_filter(array_map('trim', explode(',', $ligne['Tickets:primary_artist'] ?? '')))),
            'prixMin' => $prix === [] ? null : min($prix),
            'prixMax' => $prix === [] ? null : max($prix),
            'gratuit' => is_numeric($ligne['search_price'] ?? null) && (float) $ligne['search_price'] === 0.0,
        ];
    }
}
