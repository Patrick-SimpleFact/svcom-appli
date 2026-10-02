<?php

namespace App\Filament\Resources\Sources;

use App\Enums\TypeAccesSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\Pages\ViewSource;
use App\Models\Source;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Sources déclarées (F7.3). Une source se désactive d'un clic ; ses événements disparaissent à la publication suivante.
 */
class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $modelLabel = 'source';

    protected static ?string $pluralModelLabel = 'sources';

    protected static ?string $recordTitleAttribute = 'nom';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('nom'),
            TextEntry::make('code'),
            TextEntry::make('type_acces')->label('Accès')->badge(),
            TextEntry::make('type_lien')->label('Lien de réservation')->badge(),
            TextEntry::make('licence'),
            TextEntry::make('mention_obligatoire')->label('Mention obligatoire')->placeholder('Aucune'),
            IconEntry::make('actif')->boolean(),
            TextEntry::make('zone')->label('Zone')->state(fn (Source $record): string => $record->zone ? implode(', ', $record->zone['villes'] ?? []) : 'Toute la France'),
            TextEntry::make('derniere_verification_le')->label('Dernière vérification')->dateTime('d/m/Y H:i', 'Europe/Paris')->placeholder('Jamais'),
            TextEntry::make('prochain_controle')->label('Prochain contrôle')
                ->state(fn (Source $record): string => $record->actif ? self::prochainControle()->format('d/m/Y H:i') : 'Source désactivée'),
            TextEntry::make('derniere_version_vue')->label('Dernière version vue à la source')->placeholder('—'),
            TextEntry::make('erreur_detection')->label('Erreur de détection')->color('danger')->placeholder('Aucune'),
            TextEntry::make('remarques')->columnSpanFull()->placeholder('—'),
            KeyValueEntry::make('fiabilite')->label('Fiabilité par champ'),
            KeyValueEntry::make('config')->label('Paramètres du connecteur')->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('nom')->description(fn (Source $record): string => $record->licence),
                TextColumn::make('type_acces')->label('Accès')->badge()
                    ->color(fn (TypeAccesSource $state): string => $state === TypeAccesSource::Awin ? 'warning' : 'info'),
                TextColumn::make('type_lien')->label('Lien de réservation')->badge()->color('gray'),
                TextColumn::make('derniere_verification_le')->label('Dernière vérification')
                    ->dateTime('d/m H:i', 'Europe/Paris')->placeholder('Jamais')
                    ->description(fn (Source $record): ?string => $record->erreur_detection ? '⚠️ '.mb_strimwidth($record->erreur_detection, 0, 60, '…') : null),
                ToggleColumn::make('actif')->label('Active'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** Le détecteur passe toutes les 30 min (à l'heure et à la demie). */
    public static function prochainControle(): CarbonInterface
    {
        $maintenant = now('Europe/Paris')->startOfMinute();

        return $maintenant->minute < 30
            ? $maintenant->setTime($maintenant->hour, 30)
            : $maintenant->addHour()->setTime($maintenant->hour, 0);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'view' => ViewSource::route('/{record}'),
        ];
    }
}
