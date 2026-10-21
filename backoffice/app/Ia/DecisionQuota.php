<?php

namespace App\Ia;

use Carbon\CarbonImmutable;

/** Réponse du garde-fou du forfait : peut-on consommer, pourquoi, et sinon quand réessayer (null : pas avant une action humaine). */
final readonly class DecisionQuota
{
    public function __construct(
        public bool $autorise,
        public string $motif,
        public ?CarbonImmutable $repriseLe = null,
        public ?EtatQuota $etat = null,
    ) {}
}
