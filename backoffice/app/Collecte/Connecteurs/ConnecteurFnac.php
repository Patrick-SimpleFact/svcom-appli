<?php

namespace App\Collecte\Connecteurs;

use App\Collecte\AnnonceNormalisee;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Fnac Spectacles (Awin, flux n°23455, COLLECTE §3) : une ligne = une séance (date dans `Tickets:event_date`,
 * heure dans `custom_7`), `parent_product_id` = le spectacle. Gros volume, dont beaucoup d'expositions et de parcs
 * (écartés par le filtre « spectacle vivant »). Pièges : coordonnées 0,0 = inconnues ; stock « 6 - NO_AMOUNT » = complet.
 */
class ConnecteurFnac extends ConnecteurAwin
{
    private const FUSEAU = 'Europe/Paris';

    protected function annonces(array $ligne): iterable
    {
        $produit = trim($ligne['merchant_product_id'] ?? '');
        $stock = $ligne['stock_quantity'] ?? '';

        // Séance annulée, ou à l'étranger : rien à publier.
        if (str_starts_with($stock, '1 -') || ! in_array(trim($ligne['custom_5'] ?? 'FR'), ['FR', ''], true)) {
            return;
        }

        $jour = trim($ligne['Tickets:event_date'] ?? '');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) {
            throw new InvalidArgumentException("date illisible ({$jour}).");
        }

        $heure = trim($ligne['custom_7'] ?? '');
        $heureConnue = preg_match('/^\d{2}:\d{2}$/', $heure) === 1 && $heure !== '00:00';
        $debut = CarbonImmutable::parse($jour.' '.($heureConnue ? $heure : '00:00'), self::FUSEAU);

        if ($debut->lt(CarbonImmutable::today(self::FUSEAU))) {
            return; // séance passée
        }

        $prix = array_filter([
            $this->nombre($ligne['Tickets:min_price'] ?? null),
            $this->nombre($ligne['Tickets:max_price'] ?? null),
            $this->nombre($ligne['search_price'] ?? null),
        ]);

        yield new AnnonceNormalisee(
            identifiantExterne: $produit,
            titre: trim($ligne['product_name'] ?? '') ?: trim($ligne['Tickets:event_name'] ?? ''),
            debut: $debut,
            heureConnue: $heureConnue,
            lien: $ligne['aw_deep_link'] ?? '',
            lieuNom: trim($ligne['Tickets:venue_name'] ?? '') ?: null,
            lieuAdresse: trim($ligne['custom_4'] ?? '') ?: null,
            lieuCodePostal: trim($ligne['custom_3'] ?? '') ?: null,
            lieuVille: trim($ligne['Tickets:venue_address'] ?? '') ?: null, // contient en pratique la commune
            lieuLatitude: $this->nombre($ligne['Tickets:latitude'] ?? null),
            lieuLongitude: $this->nombre($ligne['Tickets:longitude'] ?? null),
            categoriesSource: array_values(array_unique(array_filter(array_map('trim', [
                $ligne['merchant_product_category_path'] ?? '',
                $ligne['merchant_product_second_category'] ?? '',
            ]), fn (string $c) => $c !== '' && $c !== 'None'))),
            description: $this->texte($ligne['description'] ?? null),
            imageUrl: trim($ligne['merchant_image_url'] ?? '') ?: null,
            artistes: array_values(array_filter([trim($ligne['Tickets:primary_artist'] ?? ''), trim($ligne['Tickets:second_artist'] ?? '')])),
            prixMin: $prix === [] ? null : min($prix),
            prixMax: $prix === [] ? null : max($prix),
            complet: str_starts_with($stock, '6 -'),
            identifiantSpectacle: trim($ligne['parent_product_id'] ?? '') ?: null,
        );
    }
}
