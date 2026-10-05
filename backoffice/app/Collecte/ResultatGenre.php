<?php

namespace App\Collecte;

use App\Models\Genre;

/**
 * Genre retenu pour une annonce, et d'où il vient : correspondance de catégorie, mot trouvé, ou « Autres » par défaut.
 */
final readonly class ResultatGenre
{
    public const PAR_CORRESPONDANCE = 'correspondance';

    public const PAR_MOT = 'mot';

    public const PAR_DEFAUT = 'defaut';

    public function __construct(
        public Genre $genre,
        public bool $jeunePublic,
        public ?string $classificationFine,
        public string $origine,
    ) {}
}
