<?php

namespace App\Filament\Resources\ATrier;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Filament\Resources\ATrier\Pages\ListATrier;
use App\Models\ElementATraiter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * File « À trier » (F7.4, F7.10) : annonces au score douteux, non publiées en attendant.
 * Lecture seule pour l'instant : les décisions (garder, exclure) arrivent avec la boîte de travail (A01).
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
            ->columns([
                TextColumn::make('donnees.titre')->label('Titre')->wrap()
                    ->description(fn (ElementATraiter $record): string => collect([$record->donnees['lieu'] ?? null, $record->donnees['ville'] ?? null])->filter()->implode(' · ')),
                TextColumn::make('cible.nom')->label('Source'),
                TextColumn::make('donnees.categories_source')->label('Catégories de la source')->badge()->placeholder('—'),
                TextColumn::make('donnees.score')->label('Score'),
                TextColumn::make('donnees.motifs')->label('Mots trouvés')->listWithLineBreaks()->placeholder('Aucun mot connu'),
                TextColumn::make('statut')->badge()
                    ->color(fn (StatutElement $state): string => $state === StatutElement::EnAttente ? 'warning' : 'gray'),
                TextColumn::make('created_at')->label('Mis de côté le')->dateTime('d/m/Y H:i', 'Europe/Paris'),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutElement::class)->default(StatutElement::EnAttente->value),
            ])
            ->recordUrl(null);
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
