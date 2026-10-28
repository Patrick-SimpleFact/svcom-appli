<?php

namespace App\Filament\Widgets\Audience;

use App\Statistiques\Audience;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Base des courbes de la page Audience : couleurs (palette validée pour les daltonismes, clair et sombre),
 * repères des actions de communication (triangles violets, leur libellé au survol) et infobulles.
 */
abstract class GraphiqueAudience extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $maxHeight = '300px';

    public const BLEU = '#2a78d6';

    public const ORANGE = '#eb6834';

    public const VERT = '#1baf7a';

    public const VIOLET = '#6250d6';

    protected function audience(): Audience
    {
        return app(Audience::class);
    }

    /** Le jour de référence choisi en tête de la page (aujourd'hui par défaut). */
    protected function reference(): CarbonImmutable
    {
        return $this->audience()->referenceFiltre($this->pageFilters['jour'] ?? null);
    }

    /** « 30 derniers jours » ou « 30 jours jusqu'au 20/10 ». */
    protected function periode(int $nbJours): string
    {
        $reference = $this->reference();

        return $reference->equalTo($this->audience()->debutDuJour())
            ? "{$nbJours} derniers jours"
            : "{$nbJours} jours jusqu’au ".$reference->translatedFormat('d/m/Y');
    }

    /**
     * Triangles posés au-dessus des valeurs des jours (ou heures) où une action de communication a eu lieu.
     *
     * @param  list<string>  $cles  clés des points (« AAAA-MM-JJ » ou « AAAA-MM-JJ HH »)
     * @param  list<int|float|null>  $hauteurs  hauteur de chaque point (sommet de la barre ou de la courbe)
     * @param  array<string, list<string>>  $actions  clé → libellés
     */
    protected function repereActions(array $cles, array $hauteurs, array $actions): ?array
    {
        if ($actions === []) {
            return null;
        }

        return [
            'type' => 'line',
            'label' => 'Actions de communication',
            'data' => array_map(fn (string $cle, $h) => isset($actions[$cle]) ? (float) ($h ?? 0) : null, $cles, $hauteurs),
            'libelles' => array_map(fn (string $cle) => $actions[$cle] ?? null, $cles),
            'showLine' => false,
            'pointStyle' => 'triangle',
            'pointRadius' => 9,
            'pointHoverRadius' => 11,
            'borderColor' => '#ffffff',
            'borderWidth' => 2,
            'backgroundColor' => self::VIOLET,
            'stack' => 'actions',
            'order' => -1,
        ];
    }

    /** @param  list<string>  $jours  « AAAA-MM-JJ » → « 28/10 » */
    protected function etiquettesJours(array $jours): array
    {
        return array_map(fn (string $j) => CarbonImmutable::parse($j)->translatedFormat('D d/m'), $jours);
    }

    protected function debutPeriode(array $jours): CarbonImmutable
    {
        return CarbonImmutable::parse($jours[0], Audience::FUSEAU)->startOfDay();
    }

    /** Options communes : légende en bas, axe des valeurs entier à partir de 0, infobulles (libellés des actions, détails). */
    protected function options(bool $empile = false, ?int $maximum = null, string $suffixe = ''): RawJs
    {
        $empile = $empile ? 'true' : 'false';
        $max = $maximum === null ? '' : "max: {$maximum},";

        return RawJs::make(<<<JS
            {
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (c) => c.dataset.libelles
                                ? c.dataset.libelles[c.dataIndex]
                                : (c.dataset.details ? c.dataset.label + ' : ' + c.dataset.details[c.dataIndex] : c.dataset.label + ' : ' + c.formattedValue + '{$suffixe}'),
                        },
                    },
                },
                scales: {
                    x: { stacked: {$empile}, grid: { display: false } },
                    y: { stacked: {$empile}, beginAtZero: true, {$max} ticks: { precision: 0 } },
                },
            }
        JS);
    }
}
