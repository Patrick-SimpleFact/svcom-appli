<?php

namespace App\Filament\Widgets\Audience;

use App\Statistiques\Audience;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** Le parcours sur 14 jours, en tableau : ouverture de l'app, fiche vue, clic vers une billetterie (nombres et parts). */
class ParcoursParJour extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.audience-parcours';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $audience = app(Audience::class);
        $reference = $audience->referenceFiltre($this->pageFilters['jour'] ?? null);
        $actions = $audience->actions($reference->subDays(13), $reference->endOfDay());

        return [
            'lignes' => array_map(fn (array $l) => [
                ...$l,
                'libelle' => CarbonImmutable::parse($l['jour'])->translatedFormat('l d/m'),
                'part_fiches' => $l['actifs'] > 0 ? round(100 * $l['fiches'] / $l['actifs']) : null,
                'part_clics' => $l['actifs'] > 0 ? round(100 * $l['clics'] / $l['actifs']) : null,
                'actions' => $actions[$l['jour']] ?? [],
            ], $audience->parcours(14, $reference)),
            'periode' => $reference->equalTo($audience->debutDuJour()) ? '14 derniers jours' : '14 jours jusqu’au '.$reference->translatedFormat('d/m/Y'),
        ];
    }
}
