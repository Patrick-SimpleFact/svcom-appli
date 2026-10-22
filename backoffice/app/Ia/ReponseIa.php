<?php

namespace App\Ia;

use App\Models\AppelIa;

/** Réponse du modèle, déjà décodée et vérifiée contre le schéma demandé. */
final readonly class ReponseIa
{
    /** @param  array<string, mixed>  $contenu */
    public function __construct(
        public array $contenu,
        public string $modele,
        public AppelIa $appel,
    ) {}
}
