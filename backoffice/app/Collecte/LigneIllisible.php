<?php

namespace App\Collecte;

/**
 * Ligne d'un flux impossible à traduire en annonce : comptée et signalée, sans arrêter la collecte.
 */
final readonly class LigneIllisible
{
    public function __construct(
        public string $raison,
        public ?string $identifiant = null,
    ) {}
}
