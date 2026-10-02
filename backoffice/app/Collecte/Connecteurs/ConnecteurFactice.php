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
 * Connecteur de démonstration et de test : produit cinq annonces d'exemple (gardées, à trier, exclue, illisible).
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
            ['id' => 'F-1', 'titre' => 'Exemple de comédie', 'debut' => $demain->toIso8601String(), 'lieu' => 'Théâtre du Chêne noir', 'ville' => 'Avignon', 'prix' => 18, 'lien' => 'https://exemple.fr/1'],
            ['id' => 'F-2', 'titre' => 'Exemple de concert', 'debut' => $demain->addDay()->toIso8601String(), 'lieu' => 'La Manutention', 'ville' => 'Avignon', 'prix' => 12, 'lien' => 'https://exemple.fr/2'],
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
            lieuVille: $ligne['ville'],
            prixMin: $ligne['prix'] ?? null,
        );
    }

    public function extensionBrut(): string
    {
        return 'json';
    }
}
