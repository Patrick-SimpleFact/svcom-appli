<?php

namespace App\Filament\Resources\SpectaclesAControler;

use App\Actions\SeparerSpectacle;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Filament\Resources\Spectacles\SpectacleResource;
use App\Filament\Resources\SpectaclesAControler\Pages\ListSpectaclesAControler;
use App\Filament\Support\Urgence;
use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\Spectacle;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * File « Spectacles à contrôler » (K07b) : des dates de plusieurs lieux réunies sous un spectacle sur la foi du titre
 * seul (artistes inconnus). Elles restent publiées ensemble ; « Séparer » détache les dates de certains lieux.
 */
class SpectacleAControlerResource extends Resource
{
    protected static ?string $model = ElementATraiter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'Spectacles à contrôler';

    protected static ?string $modelLabel = 'spectacle à contrôler';

    protected static ?string $pluralModelLabel = 'spectacles à contrôler';

    protected static ?string $slug = 'spectacles-a-controler';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('file', FileATraiter::SpectacleAControler)->with('cible');
    }

    public static function getNavigationBadge(): ?string
    {
        $nombre = static::getEloquentQuery()->where('statut', StatutElement::EnAttente)->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    /**
     * Les lieux réunis sous le spectacle : nom, ville, nombre de dates à venir, billetteries, artistes.
     *
     * @return Collection<int, array{lieu: string, dates: int, sources: string, artistes: string}>
     */
    public static function lieux(?Spectacle $spectacle): Collection
    {
        if ($spectacle === null) {
            return collect();
        }

        return Offre::with(['lieu.ville', 'source'])->where('spectacle_id', $spectacle->id)->whereNull('disparue_le')->get()
            ->groupBy('lieu_id')
            ->map(fn (Collection $offres) => [
                'lieu' => collect([$offres->first()->lieu?->nom, $offres->first()->lieu?->ville?->nom])->filter()->implode(' · '),
                'dates' => $offres->pluck('date_locale')->unique()->count(),
                'sources' => $offres->pluck('source.nom')->unique()->implode(', '),
                'artistes' => $offres->flatMap(fn (Offre $o) => $o->donnees_normalisees['artistes'] ?? [])->unique()->implode(', ') ?: 'artistes inconnus',
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => Urgence::trier($query))
            ->columns([
                Urgence::colonneUrgence(),
                Urgence::colonneEcheance(),
                TextColumn::make('cible.titre')->label('Spectacle')->weight('bold')->wrap()
                    ->url(fn (ElementATraiter $record): ?string => $record->cible ? SpectacleResource::getUrl('view', ['record' => $record->cible]) : null),
                TextColumn::make('lieux')->label('Lieux réunis')->listWithLineBreaks()->wrap()
                    ->state(fn (ElementATraiter $record): array => static::lieux($record->cible)
                        ->map(fn (array $l) => "{$l['lieu']} — {$l['dates']} date(s) — {$l['sources']} — {$l['artistes']}")->values()->all()),
                TextColumn::make('statut')->badge()
                    ->color(fn (StatutElement $state): string => $state === StatutElement::EnAttente ? 'warning' : 'gray'),
                TextColumn::make('created_at')->label('Ajouté le')->dateTime('d/m/Y H:i', 'Europe/Paris'),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutElement::class)->default(StatutElement::EnAttente->value),
            ])
            ->recordActions([
                static::actionConfirmer(),
                static::actionSeparer(),
            ])
            ->recordUrl(null);
    }

    /** Les dates réunies sont bien le même spectacle (aussi dans la boîte de travail). */
    public static function actionConfirmer(): Action
    {
        return Action::make('confirmer')->label('Même spectacle')->icon(Heroicon::OutlinedCheck)->color('success')
            ->visible(fn (ElementATraiter $record): bool => $record->file === FileATraiter::SpectacleAControler && $record->statut === StatutElement::EnAttente && $record->cible !== null)
            ->action(function (ElementATraiter $record): void {
                app(SeparerSpectacle::class)->confirmer($record->cible);
                Notification::make()->success()->title('Regroupement confirmé')->send();
            });
    }

    /** Détacher les dates de certains lieux dans un autre spectacle (aussi dans la boîte de travail). */
    public static function actionSeparer(): Action
    {
        return Action::make('separer')->label('Séparer')->icon(Heroicon::OutlinedScissors)->color('danger')
            ->visible(fn (ElementATraiter $record): bool => $record->file === FileATraiter::SpectacleAControler && $record->statut === StatutElement::EnAttente && $record->cible !== null)
            ->modalHeading(fn (ElementATraiter $record): string => "Séparer « {$record->cible?->titre} »")
            ->modalDescription('Les dates des lieux cochés deviennent un autre spectacle (même titre, autre troupe). Les autres restent ici.')
            ->schema(fn (ElementATraiter $record): array => [
                CheckboxList::make('lieux')->label('Lieux à détacher')->required()
                    ->options(static::lieux($record->cible)->map(fn (array $l) => "{$l['lieu']} — {$l['dates']} date(s) — {$l['artistes']}")->all()),
            ])
            ->action(function (ElementATraiter $record, array $data): void {
                app(SeparerSpectacle::class)->handle($record->cible, array_map('intval', $data['lieux']));
                Notification::make()->success()->title('Dates séparées dans un nouveau spectacle')->send();
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSpectaclesAControler::route('/')];
    }
}
