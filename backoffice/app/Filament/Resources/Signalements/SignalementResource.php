<?php

namespace App\Filament\Resources\Signalements;

use App\Contributions\Contributions;
use App\Enums\ActionSignalement;
use App\Enums\MotifSignalement;
use App\Enums\StatutSignalement;
use App\Filament\Resources\Signalements\Pages\ListSignalements;
use App\Filament\Resources\Spectacles\SpectacleResource;
use App\Models\Signalement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * File « Signalements » (F5.6, F7.10) : erreurs signalées par les utilisateurs sur une séance.
 * Le super-admin corrige (fiche du spectacle), masque la séance ou rejette ; jamais de masquage automatique.
 * Une décision vaut pour tous les signalements en attente de la même séance.
 */
class SignalementResource extends Resource
{
    protected static ?string $model = Signalement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Contributions';

    protected static ?string $navigationLabel = 'Signalements';

    protected static ?string $modelLabel = 'signalement';

    protected static ?string $pluralModelLabel = 'signalements';

    protected static ?string $slug = 'signalements';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['representation.spectacle', 'representation.lieu.ville'])
            ->withCount(['autresDeLaSeance as meme_seance' => fn (Builder $q) => $q->where('statut', StatutSignalement::Nouveau)]);
    }

    public static function getNavigationBadge(): ?string
    {
        $nombre = Signalement::where('statut', StatutSignalement::Nouveau)->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('motif')->badge()->color('warning'),
                TextColumn::make('representation.spectacle.titre')->label('Spectacle')->weight('bold')->wrap()
                    ->url(fn (Signalement $record): ?string => $record->representation?->spectacle ? SpectacleResource::getUrl('view', ['record' => $record->representation->spectacle]) : null),
                TextColumn::make('seance')->label('Séance')->wrap()
                    ->state(fn (Signalement $record): string => collect([
                        $record->representation?->debut?->setTimezone('Europe/Paris')->format('d/m/Y H:i') ?? $record->representation?->date_locale?->format('d/m/Y'),
                        $record->representation?->lieu?->nom,
                        $record->representation?->lieu?->ville?->nom,
                    ])->filter()->implode(' · ')),
                TextColumn::make('representation.statut')->label('État de la séance')->badge(),
                TextColumn::make('commentaire')->wrap()->placeholder('—'),
                TextColumn::make('meme_seance')->label('Signalée')
                    ->formatStateUsing(fn (int $state): string => $state > 1 ? "{$state} fois" : '1 fois'),
                TextColumn::make('statut')->badge(),
                TextColumn::make('action')->label('Suite')->placeholder('—'),
                TextColumn::make('created_at')->label('Reçu le')->dateTime('d/m/Y H:i', 'Europe/Paris'),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutSignalement::class)->default(StatutSignalement::Nouveau->value),
                SelectFilter::make('motif')->options(MotifSignalement::class),
            ])
            ->recordActions([
                static::decision('corrige', 'Corrigé', Heroicon::OutlinedPencilSquare, 'success', ActionSignalement::Corrige,
                    'À faire après avoir corrigé la séance sur la fiche du spectacle (lien sur le titre).'),
                static::decision('masquer', 'Masquer la séance', Heroicon::OutlinedEyeSlash, 'danger', ActionSignalement::Masque,
                    'La séance disparaît de l’app tout de suite. Réversible depuis la fiche du spectacle (« Réafficher »).'),
                static::decision('rejeter', 'Rejeter', Heroicon::OutlinedXMark, 'gray', ActionSignalement::Rien,
                    'Le signalement n’est pas fondé : rien ne change dans l’app.'),
            ])
            ->recordUrl(null);
    }

    private static function decision(string $nom, string $libelle, Heroicon $icone, string $couleur, ActionSignalement $suite, string $effet): Action
    {
        return Action::make($nom)->label($libelle)->icon($icone)->color($couleur)
            ->visible(fn (Signalement $record): bool => $record->statut === StatutSignalement::Nouveau)
            ->requiresConfirmation()
            ->modalDescription(fn (Signalement $record): string => $effet.($record->meme_seance > 1 ? " Vaut pour les {$record->meme_seance} signalements de cette séance." : ''))
            ->action(function (Signalement $record) use ($suite): void {
                $nombre = app(Contributions::class)->traiterSignalements($record, $suite, auth()->id());
                Notification::make()->success()->title("{$nombre} signalement(s) traité(s)")->send();
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSignalements::route('/')];
    }
}
