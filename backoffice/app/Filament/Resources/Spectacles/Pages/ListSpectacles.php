<?php

namespace App\Filament\Resources\Spectacles\Pages;

use App\Filament\Resources\Spectacles\SpectacleResource;
use App\Models\Spectacle;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSpectacles extends ListRecords
{
    protected static string $resource = SpectacleResource::class;

    /** Les spectacles créés aujourd'hui ou hier (jour de Paris), pour vérifier vite ce que la collecte a ajouté. */
    public function getTabs(): array
    {
        return [
            'tous' => Tab::make('Tous'),
            'aujourdhui' => Tab::make('Nouveaux aujourd’hui')
                ->badge(fn (): int => self::nouveaux(Spectacle::query(), 0)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => self::nouveaux($query, 0)),
            'hier' => Tab::make('Nouveaux hier')
                ->badge(fn (): int => self::nouveaux(Spectacle::query(), 1)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => self::nouveaux($query, 1)),
        ];
    }

    public static function nouveaux(Builder $query, int $joursAvant): Builder
    {
        $debut = CarbonImmutable::today('Europe/Paris')->subDays($joursAvant);

        return $query->where('spectacles.created_at', '>=', $debut)->where('spectacles.created_at', '<', $debut->addDay())->where('spectacles.demo', false);
    }
}
