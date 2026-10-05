<?php

namespace App\Collecte\Connecteurs;

use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteur;
use App\Collecte\DetecteVersion;
use App\Collecte\LigneIllisible;
use App\Models\Source;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Base des flux produits Awin (COLLECTE §3) : la « liste des flux » du compte (adresse secrète) donne, pour chaque
 * annonceur, l'adresse de son fichier CSV compressé et la date de sa dernière mise à jour (« Last Imported »).
 * Chaque annonceur remplit les colonnes à sa façon : la traduction d'une ligne est propre à chaque connecteur.
 */
abstract class ConnecteurAwin implements Connecteur, DetecteVersion
{
    /**
     * Traduit une ligne du CSV (colonnes → valeurs) en annonces (plusieurs si la ligne porte plusieurs séances).
     *
     * @return iterable<AnnonceNormalisee>
     */
    abstract protected function annonces(array $ligne): iterable;

    public function versionDisponible(Source $source): ?string
    {
        return $this->fluxDe($source)['Last Imported'] ?? null;
    }

    public function telecharger(Source $source): string
    {
        $reponse = Http::timeout(600)->retry(2, 5000, throw: false)->get($this->fluxDe($source)['URL']);

        if (! $reponse->successful()) {
            throw new RuntimeException("Téléchargement du flux Awin impossible ({$reponse->status()}).");
        }

        return $reponse->body();
    }

    public function lire(string $contenuBrut, Source $source): iterable
    {
        // Le flux compressé est lu ligne à ligne, sans être décompressé d'un bloc (Fnac : ≈ 200 Mo une fois décompressé).
        $fichier = tempnam(sys_get_temp_dir(), 'awin');
        file_put_contents($fichier, $contenuBrut);

        try {
            $flux = str_starts_with($contenuBrut, "\x1f\x8b") ? gzopen($fichier, 'rb') : fopen($fichier, 'rb');

            foreach ($this->lignesDuFlux($flux) as $numero => $ligne) {
                if ($ligne === null) {
                    yield new LigneIllisible("Ligne {$numero} : nombre de colonnes incorrect.");

                    continue;
                }

                try {
                    foreach ($this->annonces($ligne) as $annonce) {
                        yield $annonce; // pas de « yield from » : il reprendrait les clés 0, 1… de chaque ligne
                    }
                } catch (InvalidArgumentException|Throwable $erreur) {
                    yield new LigneIllisible("Ligne {$numero} : ".$erreur->getMessage(), $ligne['merchant_product_id'] ?? null);
                }
            }
        } finally {
            if (isset($flux) && is_resource($flux)) {
                fclose($flux);
            }

            @unlink($fichier);
        }
    }

    public function extensionBrut(): string
    {
        return 'csv.gz';
    }

    /** La ligne de la liste des flux qui correspond à l'annonceur de la source (`config.annonceur_awin`). */
    protected function fluxDe(Source $source): array
    {
        $adresse = config('services.awin.liste_flux');
        $annonceur = (string) ($source->config['annonceur_awin'] ?? '');

        if (blank($adresse)) {
            throw new RuntimeException('Adresse de la liste des flux Awin absente (AWIN_FEEDLIST_URL).');
        }

        $reponse = Http::timeout(60)->retry(2, 2000, throw: false)->get($adresse);

        if (! $reponse->successful()) {
            throw new RuntimeException("Liste des flux Awin inaccessible ({$reponse->status()}).");
        }

        foreach ($this->lignesCsv($reponse->body()) as $flux) {
            if ($flux !== null && trim($flux['Advertiser ID'] ?? '') === $annonceur) {
                return $flux;
            }
        }

        throw new RuntimeException("Aucun flux Awin accessible pour l'annonceur {$annonceur}.");
    }

    /**
     * CSV standard d'Awin (virgule, guillemets doublés) lu ligne à ligne. Pas de détection automatique du séparateur :
     * elle se trompait sur le flux Fnac et décalait des lignes (leçon du POC).
     *
     * @return iterable<int, array<string, string>|null> null : ligne au nombre de colonnes incorrect
     */
    protected function lignesCsv(string $csv): iterable
    {
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, $csv);
        rewind($flux);

        yield from $this->lignesDuFlux($flux);

        fclose($flux);
    }

    /** @return iterable<int, array<string, string>|null> */
    private function lignesDuFlux($flux): iterable
    {
        $entetes = fgetcsv($flux, separator: ',', enclosure: '"', escape: '');

        if ($entetes === false) {
            return;
        }

        $entetes[0] = preg_replace('/^\xEF\xBB\xBF/', '', $entetes[0]); // marque d'ordre des octets
        $numero = 1;

        while (($valeurs = fgetcsv($flux, separator: ',', enclosure: '"', escape: '')) !== false) {
            $numero++;

            if ($valeurs === [null]) {
                continue; // ligne vide
            }

            yield $numero => count($valeurs) === count($entetes) ? array_combine($entetes, $valeurs) : null;
        }
    }

    /** Nombre décimal du flux, ou null si vide ou nul (la Fnac met 0.0 pour « inconnu »). */
    protected function nombre(?string $valeur): ?float
    {
        return is_numeric($valeur) && (float) $valeur != 0.0 ? (float) $valeur : null;
    }

    protected function texte(?string $valeur): ?string
    {
        $valeur = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</div>', '</p>'], "\n", (string) $valeur)), ENT_QUOTES | ENT_HTML5));
        $valeur = preg_replace("/[ \t]+/", ' ', preg_replace("/\n{2,}/", "\n", $valeur));

        return $valeur === '' ? null : $valeur;
    }
}
