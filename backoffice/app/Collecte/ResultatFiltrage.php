<?php

namespace App\Collecte;

use App\Enums\IssueFiltrage;

final readonly class ResultatFiltrage
{
    /** @param  list<string>  $motifs  explication du score, affichée dans la file « À trier » */
    public function __construct(
        public IssueFiltrage $issue,
        public int $score,
        public array $motifs,
    ) {}
}
