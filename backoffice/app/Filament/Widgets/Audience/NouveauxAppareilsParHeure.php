<?php

namespace App\Filament\Widgets\Audience;

use App\Statistiques\Audience;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;

/**
 * Nouveaux appareils heure par heure, pour deux jours au choix (par défaut aujourd'hui et hier), avec les actions de
 * communication de ces deux jours : on voit l'effet d'une action dans les heures qui suivent.
 */
class NouveauxAppareilsParHeure extends GraphiqueAudience
{
    use HasFiltersSchema;

    protected ?string $heading = 'Nouveaux appareils par heure';

    protected int|string|array $columnSpan = 'full';

    public function filtersSchema(Schema $schema): Schema
    {
        $aujourdhui = CarbonImmutable::now(Audience::FUSEAU)->toDateString();

        return $schema->components([
            // Vides : le jour de référence de la page, comparé à la veille.
            DatePicker::make('jour')->label('Jour')->placeholder('Jour de référence')->maxDate($aujourdhui)->native(false)->displayFormat('d/m/Y')->live(),
            DatePicker::make('comparaison')->label('Comparé à')->placeholder('La veille')->maxDate($aujourdhui)->native(false)->displayFormat('d/m/Y')->live(),
        ]);
    }

    public function getDescription(): ?string
    {
        [$jour, $comparaison] = $this->jours();

        return 'Trait plein : '.$this->nom($jour).'. Pointillés : '.$this->nom($comparaison).'. Le bouton entonnoir permet de choisir les deux jours.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        [$jour, $comparaison] = $this->jours();
        $donnees = $this->audience()->nouveauxParHeure($jour, $comparaison);
        $heures = range(0, 23);
        $cles = array_map(fn (int $h) => sprintf('%02d', $h), $heures);

        // Un repère par heure : posé sur la plus haute des deux courbes, libellés des deux jours réunis.
        $parHeure = [];
        foreach ([$comparaison, $jour] as $j) {
            $actions = $this->audience()->actions($j, $j->endOfDay(), parHeure: true);
            foreach ($cles as $h) {
                foreach ($actions[$j->format('Y-m-d').' '.$h] ?? [] as $libelle) {
                    $parHeure[$h][] = $j->translatedFormat('d/m').' '.$libelle;
                }
            }
        }
        $hauteurs = array_map(fn (int $h) => max($donnees['jour'][$h] ?? 0, $donnees['comparaison'][$h] ?? 0), $heures);
        $ligne = ['tension' => 0.25, 'borderWidth' => 2, 'pointRadius' => 3, 'spanGaps' => false];

        return [
            'labels' => array_map(fn (int $h) => $h.' h', $heures),
            'datasets' => array_values(array_filter([
                ['label' => ucfirst($this->nom($jour)), 'data' => $donnees['jour'], 'borderColor' => self::BLEU, 'backgroundColor' => self::BLEU, ...$ligne],
                ['label' => ucfirst($this->nom($comparaison)), 'data' => $donnees['comparaison'], 'borderColor' => self::ORANGE, 'backgroundColor' => self::ORANGE, 'borderDash' => [6, 4], ...$ligne],
                $this->repereActions($cles, $hauteurs, $parHeure),
            ])),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->options();
    }

    /** @return array{CarbonImmutable, CarbonImmutable} le jour et le jour de comparaison choisis (minuit, heure de Paris) */
    private function jours(): array
    {
        $filtres = $this->filters ?? [];
        $jour = filled($filtres['jour'] ?? null) ? $this->audience()->referenceFiltre($filtres['jour']) : $this->reference();

        return [$jour, filled($filtres['comparaison'] ?? null) ? $this->audience()->referenceFiltre($filtres['comparaison']) : $jour->subDay()];
    }

    private function nom(CarbonImmutable $jour): string
    {
        $aujourdhui = CarbonImmutable::now(Audience::FUSEAU)->startOfDay();

        return match (true) {
            $jour->equalTo($aujourdhui) => 'aujourd’hui',
            $jour->equalTo($aujourdhui->subDay()) => 'hier',
            default => $jour->translatedFormat('l d/m'),
        };
    }
}
