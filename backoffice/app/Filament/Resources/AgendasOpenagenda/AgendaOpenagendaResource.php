<?php

namespace App\Filament\Resources\AgendasOpenagenda;

use App\Enums\FrequenceAgenda;
use App\Filament\Resources\AgendasOpenagenda\Pages\ManageAgendasOpenagenda;
use App\Models\AgendaOpenagenda;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Agendas OpenAgenda suivis (COLLECTE §3) : pas de recherche nationale chez OpenAgenda, la collecte interroge un par
 * un les agendas actifs de cette liste. Un agenda abandonné n'est plus interrogé qu'une fois par semaine.
 */
class AgendaOpenagendaResource extends Resource
{
    protected static ?string $model = AgendaOpenagenda::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'Agendas OpenAgenda';

    protected static ?string $modelLabel = 'agenda OpenAgenda';

    protected static ?string $pluralModelLabel = 'agendas OpenAgenda';

    protected static ?string $slug = 'agendas-openagenda';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('ville'))
            ->defaultSort('nb_evenements_a_venir', 'desc')
            ->paginated([50, 100, 'all'])
            ->columns([
                TextColumn::make('nom')->searchable()->wrap()->weight('bold')
                    ->url(fn (AgendaOpenagenda $record): string => 'https://openagenda.com/fr/'.($record->slug ?? $record->uid), shouldOpenInNewTab: true),
                TextColumn::make('ville.nom')->label('Ville')->sortable()->placeholder('—'),
                IconColumn::make('officiel')->boolean(),
                TextColumn::make('nb_evenements_a_venir')->label('À venir')->numeric()->sortable(),
                TextColumn::make('dernier_evenement_le')->label('Dernier événement')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('frequence')->label('Interrogé')->badge()
                    ->color(fn (FrequenceAgenda $state): string => $state === FrequenceAgenda::Hebdomadaire ? 'warning' : 'success'),
                TextColumn::make('derniere_collecte_le')->label('Dernière interrogation')->dateTime('d/m H:i', 'Europe/Paris')->placeholder('jamais')->toggleable(),
                ToggleColumn::make('actif'),
                TextColumn::make('origine')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('ville_id')->label('Ville')->relationship('ville', 'nom')->searchable(),
                SelectFilter::make('frequence')->label('Fréquence')->options(FrequenceAgenda::class),
                TernaryFilter::make('actif'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAgendasOpenagenda::route('/')];
    }
}
