<?php

namespace App\Collecte;

/**
 * Connecteur sans version publiée (API interrogée en direct, ex. OpenAgenda) :
 * collecte à intervalle régulier, dans une plage horaire (F7.2 : toutes les 4 h, de 6 h à 22 h).
 */
interface CollecteParIntervalle
{
    public function intervalleHeures(): int;

    /** @return array{0: int, 1: int} heure de début et heure de fin (heure de Paris) */
    public function plageHoraire(): array;
}
