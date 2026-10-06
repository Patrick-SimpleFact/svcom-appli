<?php

namespace App\Filament\Resources\ATrier;

use App\Actions\DeciderTri;
use App\Enums\FileATraiter;
use App\Enums\IssueFiltrage;
use App\Enums\StatutElement;
use App\Filament\Resources\ATrier\Pages\ListATrier;
use App\Models\ElementATraiter;
use App\Models\Source;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * File « À trier » (F7.4, F7.10) : annonces au score douteux, non publiées en attendant.
 * Filtres par source, score, catégorie ; « Garder » / « Exclure », ligne par ligne ou en masse (avancé à la demande de
 * Patrick le 06/10/2026) ; lien vers l'annonce d'origine. La boîte de travail (A01) l'enrichira.
 */
class ATrierResource extends Resource
{
    protected static ?string $model = ElementATraiter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'À trier';

    protected static ?string $modelLabel = 'annonce à trier';

    protected static ?string $pluralModelLabel = 'annonces à trier';

    protected static ?string $slug = 'a-trier';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('file', FileATraiter::ATrier)->with('cible');
    }

    public static function getNavigationBadge(): ?string
    {
        $nombre = static::getEloquentQuery()->where('statut', StatutElement::EnAttente)->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('donnees.titre')->label('Titre')->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('donnees->titre', 'ilike', '%'.$search.'%'))
                    ->description(fn (ElementATraiter $record): string => collect([$record->donnees['lieu'] ?? null, $record->donnees['ville'] ?? null])->filter()->implode(' · '))
                    ->url(fn (ElementATraiter $record): ?string => $record->donnees['lien'] ?? null, shouldOpenInNewTab: true)
                    ->tooltip('Ouvrir l’annonce d’origine'),
                TextColumn::make('cible.nom')->label('Source'),
                TextColumn::make('donnees.categories_source')->label('Catégories de la source')->badge()->placeholder('—'),
                TextColumn::make('donnees.score')->label('Score'),
                TextColumn::make('donnees.motifs')->label('Mots trouvés')->listWithLineBreaks()->placeholder('Aucun mot connu'),
                TextColumn::make('decision.issue')->label('Décision')->badge()->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? IssueFiltrage::from($state)->getLabel() : null),
                TextColumn::make('statut')->badge()
                    ->color(fn (StatutElement $state): string => $state === StatutElement::EnAttente ? 'warning' : 'gray'),
                TextColumn::make('created_at')->label('Mis de côté le')->dateTime('d/m/Y H:i', 'Europe/Paris')->sortable(),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutElement::class)->default(StatutElement::EnAttente->value),
                SelectFilter::make('source')->label('Source')
                    ->options(fn (): array => Source::orderBy('nom')->pluck('nom', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('cible_type', (new Source)->getMorphClass())->where('cible_id', $data['value']) : $query),
                SelectFilter::make('score')->label('Score')
                    ->options(['0' => '0 (aucun signal)', '1' => '1 (signal faible)', 'negatif' => 'négatif'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        '0', '1' => $query->where('donnees->score', (int) $data['value']),
                        'negatif' => $query->whereRaw("(donnees->>'score')::int < 0"),
                        default => $query,
                    }),
                Filter::make('categorie')->label('Catégorie')
                    ->schema([TextInput::make('categorie')->label('Catégorie de la source contient')])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['categorie'] ?? null)
                        ? $query->whereRaw("donnees->>'categories_source' ilike ?", ['%'.$data['categorie'].'%']) : $query),
            ])
            ->recordActions([
                static::decision('garder', 'Garder', IssueFiltrage::Garde, 'success', Heroicon::OutlinedCheck),
                static::decision('exclure', 'Exclure', IssueFiltrage::Exclu, 'danger', Heroicon::OutlinedXMark),
            ])
            ->toolbarActions([
                BulkAction::make('garderSelection')->label('Garder la sélection')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->requiresConfirmation()->modalDescription('Ces spectacles seront publiés à la prochaine collecte de leur source.')
                    ->action(fn (Collection $records) => static::notifier(app(DeciderTri::class)->handle($records, IssueFiltrage::Garde), 'gardée(s)')),
                BulkAction::make('exclureSelection')->label('Exclure la sélection')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->requiresConfirmation()->modalDescription('Ces annonces seront écartées définitivement.')
                    ->action(fn (Collection $records) => static::notifier(app(DeciderTri::class)->handle($records, IssueFiltrage::Exclu), 'exclue(s)')),
            ])
            ->recordUrl(null);
    }

    private static function decision(string $nom, string $libelle, IssueFiltrage $issue, string $couleur, Heroicon $icone): Action
    {
        return Action::make($nom)->label($libelle)->icon($icone)->color($couleur)
            ->visible(fn (ElementATraiter $record): bool => $record->statut === StatutElement::EnAttente)
            ->action(fn (ElementATraiter $record) => static::notifier(app(DeciderTri::class)->handle(new Collection([$record]), $issue), $issue === IssueFiltrage::Garde ? 'gardée(s)' : 'exclue(s)'));
    }

    private static function notifier(int $nombre, string $verbe): void
    {
        Notification::make()->success()->title("{$nombre} annonce(s) {$verbe}")->send();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListATrier::route('/')];
    }
}
