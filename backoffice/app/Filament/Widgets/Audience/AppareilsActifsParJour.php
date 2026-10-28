<?php

namespace App\Filament\Widgets\Audience;

use Filament\Support\RawJs;

/** Appareils qui ont ouvert l'app chaque jour (30 jours) : on voit si les gens reviennent, pas seulement s'ils installent. */
class AppareilsActifsParJour extends GraphiqueAudience
{
    protected ?string $description = 'Appareils qui ont ouvert l’app ce jour-là, nouveaux ou non.';

    public function getHeading(): string
    {
        return 'Appareils actifs par jour ('.$this->periode(30).')';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $reference = $this->reference();
        $donnees = $this->audience()->actifsParJour(30, $reference);
        $jours = $donnees['jours'];

        return [
            'labels' => $this->etiquettesJours($jours),
            'datasets' => array_values(array_filter([
                ['label' => 'Appareils actifs', 'data' => $donnees['actifs'], 'borderColor' => self::BLEU, 'backgroundColor' => self::BLEU, 'borderWidth' => 2, 'pointRadius' => 3, 'tension' => 0.25],
                $this->repereActions($jours, $donnees['actifs'], $this->audience()->actions($this->debutPeriode($jours), $reference->endOfDay())),
            ])),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->options();
    }
}
