<?php

namespace App\Filament\Widgets\Audience;

use Filament\Support\RawJs;

/** Nouveaux appareils (installations) par jour sur 30 jours, iOS et Android empilés, avec les actions de communication. */
class NouveauxAppareilsParJour extends GraphiqueAudience
{
    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return 'Nouveaux appareils par jour ('.$this->periode(30).')';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $reference = $this->reference();
        $donnees = $this->audience()->nouveauxParJour(30, $reference);
        $jours = $donnees['jours'];
        $totaux = array_map(fn (int $i, int $a) => $i + $a, $donnees['ios'], $donnees['android']);
        $barre = ['stack' => 'appareils', 'borderRadius' => 4, 'borderSkipped' => 'start', 'maxBarThickness' => 28];

        return [
            'labels' => $this->etiquettesJours($jours),
            'datasets' => array_values(array_filter([
                ['label' => 'Android', 'data' => $donnees['android'], 'backgroundColor' => self::ORANGE, ...$barre],
                ['label' => 'iOS', 'data' => $donnees['ios'], 'backgroundColor' => self::BLEU, ...$barre],
                $this->repereActions($jours, $totaux, $this->audience()->actions($this->debutPeriode($jours), $reference->endOfDay())),
            ])),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->options(empile: true);
    }
}
