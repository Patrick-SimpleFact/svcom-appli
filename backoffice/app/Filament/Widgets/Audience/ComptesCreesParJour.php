<?php

namespace App\Filament\Widgets\Audience;

use Filament\Support\RawJs;

/** Comptes créés par jour (30 jours), hors compte de démonstration des boutiques. */
class ComptesCreesParJour extends GraphiqueAudience
{
    protected ?string $description = 'Un compte sert aux favoris, aux suivis et aux notifications.';

    public function getHeading(): string
    {
        return 'Comptes créés par jour ('.$this->periode(30).')';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $reference = $this->reference();
        $donnees = $this->audience()->comptesParJour(30, $reference);
        $jours = $donnees['jours'];

        return [
            'labels' => $this->etiquettesJours($jours),
            'datasets' => array_values(array_filter([
                ['label' => 'Comptes créés', 'data' => $donnees['comptes'], 'backgroundColor' => self::BLEU, 'borderRadius' => 4, 'borderSkipped' => 'start', 'maxBarThickness' => 28],
                $this->repereActions($jours, $donnees['comptes'], $this->audience()->actions($this->debutPeriode($jours), $reference->endOfDay())),
            ])),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->options();
    }
}
