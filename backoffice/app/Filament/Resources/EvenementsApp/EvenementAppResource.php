<?php

namespace App\Filament\Resources\EvenementsApp;

use App\Filament\Resources\EvenementsApp\Pages\ListEvenementsApp;
use App\Models\EvenementApp;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Événements d'usage envoyés par l'app (API §11, M15), en lecture seule et rafraîchis toutes les 10 s.
 * Les chiffres de chaque jour sont résumés la nuit dans stats_quotidiennes ; les détails sont supprimés après 13 mois.
 */
class EvenementAppResource extends Resource
{
    protected static ?string $model = EvenementApp::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Statistiques';

    protected static ?string $navigationLabel = 'Événements de l’app';

    protected static ?string $modelLabel = 'événement';

    protected static ?string $pluralModelLabel = 'événements de l’app';

    protected static ?string $slug = 'evenements-app';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('ville:id,nom'))
            ->defaultSort('horodatage', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('horodatage')->label('Le')->dateTime('d/m/Y H:i:s', 'Europe/Paris')->sortable(),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('donnees')->label('Détails')->wrap()->placeholder('—')
                    ->state(fn (EvenementApp $e): ?string => static::details($e->donnees)),
                TextColumn::make('ville.nom')->label('Ville')->placeholder('—'),
                TextColumn::make('appareil_hash')->label('Appareil')->fontFamily('mono')->color('gray')
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 8))
                    ->tooltip('Empreinte anonyme de l’appareil : les événements d’un même téléphone ont la même'),
            ])
            ->filters([
                SelectFilter::make('type')->options(fn () => DB::table('evenements_app')->distinct()->orderBy('type')->pluck('type', 'type')),
                Filter::make('periode')->schema([
                    DatePicker::make('du')->label('Du'),
                    DatePicker::make('au')->label('Au'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['du'] ?? null, fn ($q, $du) => $q->where('horodatage', '>=', $du))
                    ->when($data['au'] ?? null, fn ($q, $au) => $q->where('horodatage', '<', now()->parse($au)->addDay()))),
            ])
            ->recordUrl(null);
    }

    /** Données de l'événement en une ligne lisible : « spectacle_id : 812 · origine : liste_ce_soir ». */
    public static function details(?array $donnees): ?string
    {
        if (! $donnees) {
            return null;
        }

        return collect($donnees)
            ->map(fn ($valeur, $cle) => $cle.' : '.match (true) {
                is_bool($valeur) => $valeur ? 'oui' : 'non',
                $valeur === null => '—',
                is_scalar($valeur) => (string) $valeur,
                default => json_encode($valeur, JSON_UNESCAPED_UNICODE),
            })
            ->implode(' · ');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListEvenementsApp::route('/')];
    }
}
