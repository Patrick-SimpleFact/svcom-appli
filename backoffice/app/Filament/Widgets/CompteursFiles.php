<?php

namespace App\Filament\Widgets;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Filament\Pages\BoiteDeTravail;
use App\Models\ElementATraiter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * Un compteur par file : éléments en attente, dont urgents (ce soir, villes pilotes dans la semaine) ; un clic ouvre la file.
 */
class CompteursFiles extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    /** Redessiné quand la page relance le calcul (bouton de l'en-tête). */
    #[On('boite-reclassee')]
    public function rafraichir(): void {}

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $compteurs = ElementATraiter::where('statut', StatutElement::EnAttente)
            ->selectRaw('file, count(*) as total, count(*) filter (where urgence >= 2) as urgents, count(*) filter (where urgence = 3) as ce_soir')
            ->groupBy('file')
            ->get()
            ->keyBy(fn (ElementATraiter $e) => $e->file->value);

        return collect(BoiteDeTravail::RESSOURCES)
            ->map(function (string $ressource, string $file) use ($compteurs): Stat {
                $c = $compteurs->get($file);
                $total = (int) ($c->total ?? 0);
                $urgents = (int) ($c->urgents ?? 0);
                $ceSoir = (int) ($c->ce_soir ?? 0);

                return Stat::make(FileATraiter::from($file)->getLabel(), number_format($total, 0, ',', ' '))
                    ->description(match (true) {
                        $total === 0 => 'Rien en attente',
                        $urgents === 0 => 'Rien d’urgent',
                        $ceSoir > 0 => "{$urgents} urgent(s), dont {$ceSoir} ce soir en ville pilote",
                        default => "{$urgents} urgent(s)",
                    })
                    ->color(match (true) {
                        $ceSoir > 0 => 'danger', $urgents > 0 => 'warning', default => 'gray'
                    })
                    ->url($ressource::getUrl());
            })
            // Les files avec le plus d'urgences d'abord.
            ->sortByDesc(fn (Stat $stat, string $file) => ($compteurs->get($file)->ce_soir ?? 0) * 1_000_000 + ($compteurs->get($file)->urgents ?? 0))
            ->values()
            ->all();
    }
}
