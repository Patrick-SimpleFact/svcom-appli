<?php

namespace App\Filament\Resources\LieuxAVerifier;

use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\StatutElement;
use App\Filament\Resources\Lieux\LieuResource;
use App\Filament\Resources\LieuxAVerifier\Pages\ListLieuxAVerifier;
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
 * File « Lieux à vérifier » (F7.5, F7.10) : lieux créés par la collecte, absents du référentiel ou mal placés.
 * Ils sont publiés quand même. Une ligne ouvre la fiche du lieu (corriger la position, fusionner) ;
 * le bouton « vérifié » arrive avec la boîte de travail (A01).
 */
class LieuAVerifierResource extends Resource
{
    protected static ?string $model = ElementATraiter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'Lieux à vérifier';

    protected static ?string $modelLabel = 'lieu à vérifier';

    protected static ?string $pluralModelLabel = 'lieux à vérifier';

    protected static ?string $slug = 'lieux-a-verifier';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('file', FileATraiter::LieuAVerifier)->with('cible.ville');
    }

    public static function getNavigationBadge(): ?string
    {
        $nombre = static::getEloquentQuery()->where('statut', StatutElement::EnAttente)->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('priorite')->orderByDesc('id'))
            ->columns([
                TextColumn::make('cible.nom')->label('Lieu')->wrap()
                    ->description(fn (ElementATraiter $record): string => collect([$record->cible?->adresse, $record->cible?->ville?->nom])->filter()->implode(' · ')),
                TextColumn::make('donnees.motif')->label('Motif')->wrap()
                    ->color(fn (ElementATraiter $record): string => $record->priorite >= 2 ? 'warning' : 'gray'),
                TextColumn::make('cible.precision_position')->label('Position')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof PrecisionPosition ? $state->getLabel() : '—'),
                TextColumn::make('donnees.source')->label('Source')
                    ->description(fn (ElementATraiter $record): string => collect([$record->donnees['nom_source'] ?? null, $record->donnees['adresse_source'] ?? null, $record->donnees['ville_source'] ?? null])->filter()->implode(' · ')),
                TextColumn::make('donnees.annonce')->label('Vu dans')->wrap()->placeholder('—'),
                TextColumn::make('statut')->badge()
                    ->color(fn (StatutElement $state): string => $state === StatutElement::EnAttente ? 'warning' : 'gray'),
                TextColumn::make('created_at')->label('Ajouté le')->dateTime('d/m/Y H:i', 'Europe/Paris'),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutElement::class)->default(StatutElement::EnAttente->value),
            ])
            ->recordUrl(fn (ElementATraiter $record): ?string => $record->cible ? LieuResource::getUrl('edit', ['record' => $record->cible]) : null);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListLieuxAVerifier::route('/')];
    }
}
