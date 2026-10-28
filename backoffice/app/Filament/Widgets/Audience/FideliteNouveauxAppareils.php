<?php

namespace App\Filament\Widgets\Audience;

use Filament\Support\RawJs;

/**
 * Fidélité des nouveaux appareils, par jour d'installation (30 jours) : part de ceux qui ont rouvert l'app le lendemain,
 * et dans les 7 jours. Distingue une action qui attire du monde d'une action qui n'attire que des curieux.
 */
class FideliteNouveauxAppareils extends GraphiqueAudience
{
    protected ?string $description = 'Part des appareils installés ce jour-là qui ont rouvert l’app le lendemain, et dans les 7 jours. Vide tant que la période n’est pas finie.';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return 'Fidélité des nouveaux appareils ('.$this->periode(30).')';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $reference = $this->reference();
        $jours = $this->audience()->jours(30, $reference);
        $parJour = collect($this->audience()->fidelite(30, $reference))->keyBy('jour');

        $serie = function (string $champ, string $label, string $couleur) use ($jours, $parJour): array {
            $pct = fn (?array $l) => ($l && $l[$champ] !== null && $l['nouveaux'] > 0) ? round(100 * $l[$champ] / $l['nouveaux']) : null;

            return [
                'label' => $label,
                'data' => array_map(fn (string $j) => $pct($parJour->get($j)), $jours),
                'details' => array_map(fn (string $j) => ($l = $parJour->get($j)) && $l[$champ] !== null
                    ? $pct($l).' % ('.$l[$champ].' sur '.$l['nouveaux'].')' : 'pas encore mesurable', $jours),
                'backgroundColor' => $couleur, 'borderRadius' => 4, 'borderSkipped' => 'start', 'maxBarThickness' => 18,
            ];
        };

        return [
            'labels' => $this->etiquettesJours($jours),
            'datasets' => [
                $serie('lendemain', 'Revenus le lendemain', self::BLEU),
                $serie('sept_jours', 'Revenus dans les 7 jours', self::ORANGE),
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->options(maximum: 100, suffixe: ' %');
    }
}
