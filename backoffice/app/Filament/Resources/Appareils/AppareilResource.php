<?php

namespace App\Filament\Resources\Appareils;

use App\Filament\Resources\Appareils\Pages\ListAppareils;
use App\Filament\Support\OuvertSelonProfil;
use App\Models\Appareil;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Les téléphones qui ont installé l'app, sans leur identifiant : on y coche les appareils de test (les siens, ceux des
 * essais), écartés des statistiques de la page Audience. Lecture seule sinon.
 */
class AppareilResource extends Resource
{
    use OuvertSelonProfil;

    protected static ?string $model = Appareil::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Statistiques';

    protected static ?string $navigationLabel = 'Appareils';

    protected static ?string $modelLabel = 'appareil';

    protected static ?string $pluralModelLabel = 'appareils';

    protected static ?string $slug = 'appareils';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('utilisateur:id,email'))
            ->defaultSort('derniere_ouverture', 'desc')
            ->columns([
                TextColumn::make('plateforme')->label('Système')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'ios' => 'iOS', 'android' => 'Android', default => $state
                    }),
                TextColumn::make('version_app')->label('Version de l’app'),
                TextColumn::make('premiere_ouverture')->label('Installé le')->dateTime('d/m/Y à H:i', 'Europe/Paris')->sortable(),
                TextColumn::make('derniere_ouverture')->label('Dernière ouverture')->dateTime('d/m/Y à H:i', 'Europe/Paris')->sortable(),
                TextColumn::make('nb_ouvertures')->label('Ouvertures')->numeric()->sortable(),
                TextColumn::make('utilisateur.email')->label('Compte')->placeholder('Sans compte')->searchable(),
                ToggleColumn::make('est_test')->label('Appareil de test'),
            ])
            ->filters([
                TernaryFilter::make('est_test')->label('Appareils de test')->trueLabel('Seulement les appareils de test')->falseLabel('Sans les appareils de test'),
                SelectFilter::make('plateforme')->label('Système')->options(['ios' => 'iOS', 'android' => 'Android']),
            ])
            ->toolbarActions([
                BulkAction::make('test')->label('Marquer comme appareils de test')->icon(Heroicon::OutlinedBeaker)
                    ->action(fn (Collection $appareils) => $appareils->each->update(['est_test' => true]))->deselectRecordsAfterCompletion(),
                BulkAction::make('reel')->label('Ce ne sont pas des appareils de test')->icon(Heroicon::OutlinedUser)->color('gray')
                    ->action(fn (Collection $appareils) => $appareils->each->update(['est_test' => false]))->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAppareils::route('/')];
    }
}
