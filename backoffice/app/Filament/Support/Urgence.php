<?php

namespace App\Filament\Support;

use App\Actions\PrioriserBoiteDeTravail;
use App\Models\ElementATraiter;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Présentation commune de l'urgence dans la boîte de travail et dans chaque file (F7.10, A01).
 */
class Urgence
{
    public const LIBELLES = [
        3 => 'Ce soir · ville pilote',
        2 => 'Urgent',
        1 => 'Bientôt',
        0 => 'Plus tard',
    ];

    public const COULEURS = [3 => 'danger', 2 => 'warning', 1 => 'info', 0 => 'gray'];

    /** Les plus urgents d'abord, puis la séance la plus proche, puis les plus récents. */
    public static function trier(Builder $requete): Builder
    {
        return $requete->orderByDesc('urgence')->orderByRaw('echeance asc nulls last')->orderByDesc('id');
    }

    public static function colonneUrgence(): TextColumn
    {
        return TextColumn::make('urgence')->label('Urgence')->badge()->sortable()
            ->formatStateUsing(fn (int $state): string => self::LIBELLES[$state] ?? (string) $state)
            ->color(fn (int $state): string => self::COULEURS[$state] ?? 'gray');
    }

    public static function colonneEcheance(): TextColumn
    {
        return TextColumn::make('echeance')->label('Séance')->sortable()->placeholder('—')
            ->formatStateUsing(function ($state): string {
                $jour = PrioriserBoiteDeTravail::aujourdhui();

                return match (true) {
                    $state->isSameDay($jour) => 'Ce soir',
                    $state->isSameDay($jour->addDay()) => 'Demain',
                    default => $state->translatedFormat('D j M'),
                };
            })
            ->description(fn (ElementATraiter $record): ?string => $record->ville_pilote ? 'ville pilote' : null);
    }
}
