<?php

namespace App\Api;

use App\Support\Texte;

/**
 * Précision de genre affichée dans l'app à la place du genre Spettacoli (carte, fiche).
 * `classification_fine` est la première catégorie de la source, souvent du bruit (« ShowEvent », « Arts & Theatre »,
 * « Concerts, Festivals, Musical », « Loisirs »…) : seuls les sous-genres connus ci-dessous sont montrés, avec un libellé
 * propre ; sinon l'app affiche le genre (Théâtre, Humour, Concert…). Constat du 16/10/2026 sur 351 valeurs distinctes.
 */
class Classification
{
    /** Valeur normalisée (Texte::normaliser) => libellé affiché. */
    public const SOUS_GENRES = [
        'jazz' => 'Jazz', 'blues' => 'Blues', 'soul' => 'Soul', 'funk' => 'Funk', 'gospel' => 'Gospel',
        'rock' => 'Rock', 'pop' => 'Pop', 'pop rock' => 'Pop rock', 'metal' => 'Metal', 'punk' => 'Punk',
        'rap' => 'Rap', 'hip hop' => 'Hip-hop', 'r b' => 'R&B', 'reggae' => 'Reggae',
        'electro' => 'Électro', 'techno' => 'Techno', 'house' => 'House',
        'chanson' => 'Chanson', 'chanson francaise' => 'Chanson française', 'variete' => 'Variété', 'world' => 'Musiques du monde',
        'musique classique' => 'Musique classique', 'classique' => 'Musique classique', 'classical' => 'Musique classique',
        'musique baroque' => 'Musique baroque', 'musique de chambre' => 'Musique de chambre', 'symphonique' => 'Symphonique',
        'opera' => 'Opéra', 'operette' => 'Opérette', 'ballet' => 'Ballet', 'danse contemporaine' => 'Danse contemporaine',
        'spectacle musical' => 'Spectacle musical', 'comedie musicale' => 'Comédie musicale', 'cabaret' => 'Cabaret',
        'stand up' => 'Stand-up', 'one man show' => 'One-man-show', 'one woman show' => 'One-woman-show',
        'comedie' => 'Comédie', 'drame' => 'Drame', 'tragedie' => 'Tragédie', 'boulevard' => 'Boulevard',
        'improvisation' => 'Improvisation', 'impro' => 'Improvisation', 'marionnettes' => 'Marionnettes', 'conte' => 'Conte',
        'magie' => 'Magie', 'mentalisme' => 'Mentalisme', 'cirque contemporain' => 'Cirque contemporain',
        'spectacles enfants' => 'Jeune public', 'jeune public' => 'Jeune public',
    ];

    public static function affichable(?string $classification): ?string
    {
        return self::SOUS_GENRES[Texte::normaliser($classification)] ?? null;
    }
}
