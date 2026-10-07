<?php

namespace App\Filament\Widgets;

use App\Models\Couverture;
use App\Models\Ville;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\On;

/**
 * Évolution de la couverture des villes pilotes sur 60 jours (F7.13). Échelle logarithmique :
 * Paris et Alès n'ont pas le même ordre de grandeur, on compare les tendances.
 */
class EvolutionCouverture extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Évolution (60 derniers jours)';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public ?string $filter = 'trente_jours';

    /** Couleurs fixes par ordre des villes : lisibles en clair et en sombre. */
    private const COULEURS = ['#D23A1F', '#2563EB', '#16A34A', '#9333EA', '#EA580C', '#0891B2'];

    /** Redessiné quand la page relance le calcul (bouton de l'en-tête). */
    #[On('couverture-mesuree')]
    public function rafraichir(): void {}

    protected function getFilters(): ?array
    {
        return [
            'ce_soir' => 'Ce soir',
            'week_end' => 'Ce week-end',
            'trente_jours' => '30 jours',
            'spectacles' => 'Spectacles différents (30 j)',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $champ = array_key_exists((string) $this->filter, $this->getFilters()) ? $this->filter : 'trente_jours';
        $mesures = Couverture::where('jour', '>=', today()->subDays(59))->orderBy('jour')->get();
        $jours = $mesures->pluck('jour')->map(fn ($j) => $j->toDateString())->unique()->values();

        $villes = Ville::where('est_pilote', true)->orderByDesc('population')->get();

        return [
            'datasets' => $villes->values()->map(fn (Ville $ville, int $i) => [
                'label' => $ville->nom,
                'data' => $jours->map(fn (string $jour) => $mesures->first(fn (Couverture $m) => $m->ville_id === $ville->id && $m->jour->toDateString() === $jour)?->{$champ})->all(),
                'borderColor' => self::COULEURS[$i % count(self::COULEURS)],
                'backgroundColor' => self::COULEURS[$i % count(self::COULEURS)],
                'spanGaps' => true,
            ])->all(),
            'labels' => $jours->map(fn (string $jour) => Carbon::parse($jour)->translatedFormat('d/m'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['type' => 'logarithmic', 'title' => ['display' => true, 'text' => 'échelle logarithmique']]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
