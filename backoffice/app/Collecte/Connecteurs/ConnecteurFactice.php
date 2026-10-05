<?php

namespace App\Collecte\Connecteurs;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteur;
use App\Collecte\DetecteVersion;
use App\Collecte\LigneIllisible;
use App\Models\Source;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Connecteur de démonstration et de test : produit huit annonces d'exemple (gardées, à trier, exclue, illisible).
 * Avec `simuler_echec` dans la configuration de la source, il échoue comme une source indisponible.
 */
class ConnecteurFactice implements Connecteur, DetecteVersion
{
    /** Version fictive qui change toutes les heures (ou celle imposée dans la configuration, pour les tests). */
    public function versionDisponible(Source $source): ?string
    {
        return $source->config['version'] ?? 'demo-'.now('Europe/Paris')->format('Y-m-d-H').'h';
    }

    public function telecharger(Source $source): string
    {
        if ($source->config['simuler_echec'] ?? false) {
            throw new RuntimeException('Source indisponible (échec simulé).');
        }

        $demain = CarbonImmutable::now('Europe/Paris')->addDay()->setTime(20, 30);

        return json_encode([
            // Lieux : adresse seule (géocodée), coordonnées 0,0 comme la Fnac, lieu absent du référentiel, lieu sans adresse.
            ['id' => 'F-1', 'titre' => 'Exemple de comédie', 'debut' => $demain->toIso8601String(), 'lieu' => 'Théâtre du Chêne noir', 'adresse' => '8 bis rue Sainte-Catherine', 'cp' => '84000', 'ville' => 'Avignon', 'prix' => 18, 'lien' => 'https://exemple.fr/1'],
            ['id' => 'F-2', 'titre' => 'Exemple de concert', 'debut' => $demain->addDay()->toIso8601String(), 'lieu' => 'La Manutention', 'adresse' => '4 rue des Escaliers Sainte-Anne', 'cp' => '84000', 'ville' => 'Avignon', 'lat' => 0, 'lon' => 0, 'prix' => 12, 'lien' => 'https://exemple.fr/2'],
            ['id' => 'F-6', 'titre' => 'Exemple de pièce de théâtre', 'debut' => $demain->toIso8601String(), 'lieu' => 'Théâtre de l’Observance', 'adresse' => '10 rue de l’Observance', 'cp' => '84000', 'ville' => 'Avignon', 'prix' => 15, 'lien' => 'https://exemple.fr/6'],
            ['id' => 'F-7', 'titre' => 'Exemple de spectacle d’humour', 'debut' => $demain->toIso8601String(), 'lieu' => 'Salle des fêtes', 'ville' => 'Avignon', 'lien' => 'https://exemple.fr/7'],
            // Genre : catégorie inconnue, aucun mot de genre dans le titre → « Autres » + file « À classer ».
            ['id' => 'F-8', 'titre' => 'Le Petit Prince', 'categories' => ['Spectacle pour enfants'], 'debut' => $demain->toIso8601String(), 'lieu' => 'Théâtre du Chêne noir', 'adresse' => '8 bis rue Sainte-Catherine', 'cp' => '84000', 'ville' => 'Avignon', 'prix' => 10, 'lien' => 'https://exemple.fr/8'],
            ['id' => 'F-4', 'titre' => 'Soirée surprise', 'debut' => $demain->toIso8601String(), 'lieu' => 'La Manutention', 'ville' => 'Avignon', 'lien' => 'https://exemple.fr/4'], // douteux : à trier
            ['id' => 'F-5', 'titre' => 'Exposition de photographies', 'debut' => $demain->toIso8601String(), 'lieu' => 'Maison Jean Vilar', 'ville' => 'Avignon', 'lien' => 'https://exemple.fr/5'], // exclu
            ['id' => 'F-3', 'titre' => '', 'debut' => $demain->toIso8601String(), 'lieu' => 'Lieu inconnu', 'ville' => 'Avignon', 'lien' => 'https://exemple.fr/3'], // illisible : sans titre
        ], JSON_UNESCAPED_UNICODE);
    }

    public function lire(string $contenuBrut, Source $source): iterable
    {
        foreach (json_decode($contenuBrut, true) as $ligne) {
            try {
                yield $this->annonce($ligne);
            } catch (InvalidArgumentException $erreur) {
                yield new LigneIllisible($erreur->getMessage(), $ligne['id'] ?? null);
            }
        }
    }

    private function annonce(array $ligne): AnnonceNormalisee
    {
        return new AnnonceNormalisee(
            identifiantExterne: $ligne['id'],
            titre: $ligne['titre'],
            debut: CarbonImmutable::parse($ligne['debut']),
            heureConnue: true,
            lien: $ligne['lien'],
            lieuNom: $ligne['lieu'],
            lieuAdresse: $ligne['adresse'] ?? null,
            lieuCodePostal: $ligne['cp'] ?? null,
            lieuVille: $ligne['ville'],
            lieuLatitude: isset($ligne['lat']) ? (float) $ligne['lat'] : null,
            lieuLongitude: isset($ligne['lon']) ? (float) $ligne['lon'] : null,
            categoriesSource: $ligne['categories'] ?? [],
            prixMin: $ligne['prix'] ?? null,
        );
    }

    public function extensionBrut(): string
    {
        return 'json';
    }
}
