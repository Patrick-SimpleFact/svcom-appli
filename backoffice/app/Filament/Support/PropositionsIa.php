<?php

namespace App\Filament\Support;

use App\Enums\StatutElement;
use App\Enums\StatutPropositionIa;
use App\Ia\AppliquerProposition;
use App\Ia\Assistant;
use App\Models\ElementATraiter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Les propositions de l'assistant IA dans l'écran d'une file (plan-module-ia) : colonne, « Accepter », filtre.
 * L'élément doit être chargé avec ses relations `propositionIa` (la décision qui attend d'être appliquée) et `douteIa`
 * (l'assistant n'a pas tranché : ses avis sont montrés pour aider Patrick).
 */
class PropositionsIa
{
    /** Les relations à charger avec les éléments d'une file. */
    public const RELATIONS = ['propositionIa', 'douteIa'];

    public static function colonne(): TextColumn
    {
        return TextColumn::make('proposition_ia')->label('Proposition IA')
            ->state(fn (ElementATraiter $record): ?string => match (true) {
                $record->statut !== StatutElement::EnAttente => null,
                $record->propositionIa !== null => app(Assistant::class)->tache($record->file)->libelle($record->propositionIa)." ({$record->propositionIa->confiance} %)",
                $record->douteIa !== null => 'À décider',
                default => null,
            })
            ->description(fn (ElementATraiter $record): ?string => $record->statut === StatutElement::EnAttente
                ? ($record->propositionIa?->justification ?? $record->douteIa?->justification) : null)
            ->badge()
            ->color(fn (ElementATraiter $record): string => match (true) {
                $record->propositionIa === null => 'warning',
                $record->propositionIa->confiance >= 90 => 'success',
                default => 'gray',
            })
            ->placeholder('—')
            ->wrap();
    }

    public static function actionAccepter(): Action
    {
        return Action::make('accepterIa')->label('Accepter')->icon(Heroicon::OutlinedSparkles)->color('success')
            ->tooltip('Appliquer la proposition de l’assistant IA')
            ->visible(fn (ElementATraiter $record): bool => $record->statut === StatutElement::EnAttente && $record->propositionIa !== null)
            ->action(function (ElementATraiter $record): void {
                app(AppliquerProposition::class)->handle($record->propositionIa);
                Notification::make()->success()->title('Proposition appliquée')->send();
            });
    }

    public static function actionAccepterSelection(): BulkAction
    {
        return BulkAction::make('accepterIaSelection')->label('Accepter les propositions IA')->icon(Heroicon::OutlinedSparkles)->color('success')
            ->requiresConfirmation()
            ->modalDescription('Les propositions de l’assistant IA des lignes cochées seront appliquées. Les lignes sans proposition ne changent pas.')
            ->action(function (Collection $records): void {
                [$appliquees, $erreurs] = [0, 0];

                foreach ($records->load('propositionIa') as $record) {
                    if ($record->statut !== StatutElement::EnAttente || $record->propositionIa === null) {
                        continue;
                    }
                    try {
                        app(AppliquerProposition::class)->handle($record->propositionIa);
                        $appliquees++;
                    } catch (Throwable $e) {
                        report($e);
                        $erreurs++;
                    }
                }

                Notification::make()->success()->title("{$appliquees} proposition(s) appliquée(s)")
                    ->body($erreurs > 0 ? "{$erreurs} n’ont pas pu l’être." : null)->send();
            });
    }

    public static function filtre(): TernaryFilter
    {
        return TernaryFilter::make('proposition_ia')->label('Proposition IA')
            ->trueLabel('Avec proposition')->falseLabel('Sans proposition')
            ->queries(
                true: fn (Builder $query) => $query->whereHas('propositionsIa', fn (Builder $q) => $q->where('statut', StatutPropositionIa::Proposee)),
                false: fn (Builder $query) => $query->whereDoesntHave('propositionsIa', fn (Builder $q) => $q->where('statut', StatutPropositionIa::Proposee)),
            );
    }
}
