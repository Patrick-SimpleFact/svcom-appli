<?php

namespace App\Ia;

use Carbon\CarbonImmutable;

/**
 * Ce que dit `GET https://ollama.com/api/balance`. Deux formes selon la formule :
 * - ancienne formule (celle de Patrick) : pourcentage restant et remise à zéro, sur 5 h (« session ») et sur la semaine ;
 * - formule actuelle : crédits inclus restants en dollars sur le mois.
 * Dans les deux cas, un solde de crédits achetés, qui servirait une fois l'inclus épuisé (facturé).
 */
final readonly class EtatQuota
{
    public function __construct(
        public ?float $sessionRestantPct,
        public ?CarbonImmutable $sessionRemiseLe,
        public ?float $semaineRestantPct,
        public ?CarbonImmutable $semaineRemiseLe,
        public ?float $moisRestantPct,
        public ?CarbonImmutable $moisRemiseLe,
        public float $achatsUsd,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function depuisReponse(array $json): self
    {
        $inclus = (array) ($json['included'] ?? []);
        $date = fn (?string $valeur) => $valeur ? CarbonImmutable::parse($valeur) : null;
        $pct = fn (mixed $valeur) => is_numeric($valeur) ? (float) $valeur : null;

        $mois = null;
        if (is_numeric($inclus['balance_usd'] ?? null) && is_numeric($inclus['allowance_usd'] ?? null) && $inclus['allowance_usd'] > 0) {
            $mois = 100 * $inclus['balance_usd'] / $inclus['allowance_usd'];
        }

        return new self(
            sessionRestantPct: $pct($inclus['session']['remaining_percent'] ?? null),
            sessionRemiseLe: $date($inclus['session']['resets_at'] ?? null),
            semaineRestantPct: $pct($inclus['weekly']['remaining_percent'] ?? null),
            semaineRemiseLe: $date($inclus['weekly']['resets_at'] ?? null),
            moisRestantPct: $mois,
            moisRemiseLe: $date($inclus['period']['until'] ?? null),
            achatsUsd: (float) ($json['purchased']['balance_usd'] ?? 0),
        );
    }

    /**
     * Les fenêtres connues, de la plus contraignante à la moins contraignante.
     *
     * @return list<array{nom: string, restant: float, remise: ?CarbonImmutable}>
     */
    public function fenetres(): array
    {
        $fenetres = array_filter([
            $this->sessionRestantPct === null ? null : ['nom' => '5 heures', 'restant' => $this->sessionRestantPct, 'remise' => $this->sessionRemiseLe],
            $this->semaineRestantPct === null ? null : ['nom' => 'semaine', 'restant' => $this->semaineRestantPct, 'remise' => $this->semaineRemiseLe],
            $this->moisRestantPct === null ? null : ['nom' => 'mois', 'restant' => $this->moisRestantPct, 'remise' => $this->moisRemiseLe],
        ]);
        usort($fenetres, fn ($a, $b) => $a['restant'] <=> $b['restant']);

        return array_values($fenetres);
    }
}
