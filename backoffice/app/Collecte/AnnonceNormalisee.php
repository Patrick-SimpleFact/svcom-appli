<?php

namespace App\Collecte;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Le format commun : chaque connecteur ne fait que traduire sa source en annonces de ce type (COLLECTE §2).
 */
final readonly class AnnonceNormalisee
{
    /**
     * @param  list<string>  $categoriesSource
     * @param  list<string>  $artistes
     */
    public function __construct(
        public string $identifiantExterne,
        public string $titre,
        public ?CarbonImmutable $debut,
        public bool $heureConnue,
        public string $lien,
        public ?string $lieuNom = null,
        public ?string $lieuAdresse = null,
        public ?string $lieuCodePostal = null,
        public ?string $lieuVille = null,
        public ?float $lieuLatitude = null,
        public ?float $lieuLongitude = null,
        public ?CarbonImmutable $fin = null,
        public array $categoriesSource = [],
        public ?string $description = null,
        public ?string $imageUrl = null,
        public array $artistes = [],
        public ?float $prixMin = null,
        public ?float $prixMax = null,
        public bool $gratuit = false,
        public bool $complet = false,
        public ?CarbonImmutable $misAJourSource = null,
        // Identifiant du spectacle chez la source, quand elle publie une annonce par séance (BilletRéduc) :
        // le tri et la file « À classer » raisonnent alors par spectacle, pas par séance.
        public ?string $identifiantSpectacle = null,
    ) {
        if (trim($identifiantExterne) === '') {
            throw new InvalidArgumentException('Annonce sans identifiant externe.');
        }

        if (trim($titre) === '') {
            throw new InvalidArgumentException("Annonce {$identifiantExterne} sans titre.");
        }

        if ($debut === null) {
            throw new InvalidArgumentException("Annonce {$identifiantExterne} sans date.");
        }

        if (blank($lieuNom) && blank($lieuAdresse)) {
            throw new InvalidArgumentException("Annonce {$identifiantExterne} sans lieu (ni nom ni adresse).");
        }
    }

    /** Contenu enregistré dans `offres.donnees_normalisees`. */
    public function versTableau(): array
    {
        return [
            'identifiant_externe' => $this->identifiantExterne,
            'titre' => $this->titre,
            'debut' => $this->debut?->toIso8601String(),
            'heure_connue' => $this->heureConnue,
            'fin' => $this->fin?->toIso8601String(),
            'lieu_nom' => $this->lieuNom,
            'lieu_adresse' => $this->lieuAdresse,
            'lieu_code_postal' => $this->lieuCodePostal,
            'lieu_ville' => $this->lieuVille,
            'lieu_latitude' => $this->lieuLatitude,
            'lieu_longitude' => $this->lieuLongitude,
            'categories_source' => $this->categoriesSource,
            'description' => $this->description,
            'image_url' => $this->imageUrl,
            'artistes' => $this->artistes,
            'prix_min' => $this->prixMin,
            'prix_max' => $this->prixMax,
            'gratuit' => $this->gratuit,
            'complet' => $this->complet,
            'lien' => $this->lien,
            'mis_a_jour_source' => $this->misAJourSource?->toIso8601String(),
            'identifiant_spectacle' => $this->identifiantSpectacle,
        ];
    }

    /** Clé du spectacle chez la source (l'annonce elle-même si la source ne publie pas d'identifiant de spectacle). */
    public function cleSpectacle(): string
    {
        return $this->identifiantSpectacle ?? $this->identifiantExterne;
    }

    /** Empreinte du contenu : change dès qu'une information change (détection des modifications, COLLECTE §3). */
    public function empreinte(): string
    {
        $contenu = $this->versTableau();
        unset($contenu['mis_a_jour_source']); // une simple date de mise à jour ne change pas l'annonce

        return hash('sha256', json_encode($contenu, JSON_UNESCAPED_UNICODE));
    }
}
