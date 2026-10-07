<?php

namespace App\Filament\Resources\ClicsSortants;

use App\Filament\Resources\ClicsSortants\Pages\ListClicsSortants;
use App\Models\ClicSortant;
use App\Models\Source;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Clics vers les billetteries et les organisateurs (F7.13 bis), en lecture seule.
 * Par défaut, seuls les clics comptés (ni répétés dans les 30 min, ni robots, ni tests).
 */
class ClicSortantResource extends Resource
{
    protected static ?string $model = ClicSortant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static string|UnitEnum|null $navigationGroup = 'Statistiques';

    protected static ?string $navigationLabel = 'Clics vers les billetteries';

    protected static ?string $modelLabel = 'clic';

    protected static ?string $pluralModelLabel = 'clics vers les billetteries';

    protected static ?string $slug = 'clics';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['source:id,nom', 'spectacle:id,titre', 'lieu:id,nom', 'ville:id,nom']))
            ->defaultSort('horodatage', 'desc')
            ->columns([
                TextColumn::make('horodatage')->label('Le')->dateTime('d/m/Y H:i', 'Europe/Paris')->sortable(),
                TextColumn::make('source.nom')->label('Vers')->badge()->color('gray'),
                TextColumn::make('spectacle.titre')->label('Spectacle')->wrap()->limit(50)
                    ->description(fn (ClicSortant $c): ?string => collect([$c->lieu?->nom, $c->ville?->nom])->filter()->implode(' · ')),
                TextColumn::make('origine')->placeholder('—'),
                TextColumn::make('bouton'),
                TextColumn::make('prix_affiche')->label('Prix')->money('EUR', locale: 'fr')->placeholder('—'),
                TextColumn::make('delai_avant_seance_min')->label('Avant la séance')->placeholder('—')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : ($state < 0 ? 'après le début' : ($state < 120 ? "{$state} min" : round($state / 60).' h'))),
                IconColumn::make('compte')->label('Compté')->boolean()
                    ->tooltip(fn (ClicSortant $c): string => $c->compte ? 'Compté' : 'Non compté : clic répété dans les 30 min, robot ou appel de test'),
            ])
            ->filters([
                TernaryFilter::make('compte')->label('Comptés')->boolean()->default(true),
                SelectFilter::make('source_id')->label('Billetterie')->options(fn () => Source::orderBy('nom')->pluck('nom', 'id')),
                SelectFilter::make('origine')->options(array_combine(ClicSortant::ORIGINES, ClicSortant::ORIGINES)),
                Filter::make('periode')->schema([
                    DatePicker::make('du')->label('Du'),
                    DatePicker::make('au')->label('Au'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['du'] ?? null, fn ($q, $du) => $q->where('horodatage', '>=', $du))
                    ->when($data['au'] ?? null, fn ($q, $au) => $q->where('horodatage', '<', now()->parse($au)->addDay()))),
            ])
            ->recordUrl(null);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListClicsSortants::route('/')];
    }
}
