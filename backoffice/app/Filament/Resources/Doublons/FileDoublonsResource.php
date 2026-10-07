<?php

namespace App\Filament\Resources\Doublons;

use App\Actions\DeciderDoublon;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Enums\TypeDecisionDedoublonnage;
use App\Filament\Support\Urgence;
use App\Models\ElementATraiter;
use App\Models\Offre;
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
 * Présentation commune des files de déduplication (COLLECTE §7.2) : les deux annonces côte à côte,
 * et les deux décisions possibles (même séance / séances différentes), mémorisées pour les collectes suivantes.
 * Version simple : la boîte de travail (A01) l'enrichira.
 */
abstract class FileDoublonsResource extends Resource
{
    protected static ?string $model = ElementATraiter::class;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    abstract protected static function file(): FileATraiter;

    /** Libellés des deux boutons : [fusionner, séparer]. */
    abstract public static function libellesDecisions(): array;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('file', static::file());
    }

    public static function getNavigationBadge(): ?string
    {
        $nombre = static::getEloquentQuery()->where('statut', StatutElement::EnAttente)->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function table(Table $table): Table
    {
        [$fusionner, $separer] = static::libellesDecisions();

        return $table
            ->defaultSort(fn (Builder $query) => Urgence::trier($query))
            ->columns([
                Urgence::colonneUrgence(),
                Urgence::colonneEcheance(),
                static::colonneOffre('offre_a_id', 'Séance déjà connue'),
                static::colonneOffre('offre_b_id', 'Nouvelle annonce'),
                TextColumn::make('lieu')->label('Lieu')->wrap()
                    ->state(fn (ElementATraiter $record): ?string => static::offre($record, 'offre_b_id')?->lieu?->nom),
                TextColumn::make('donnees.ecart_minutes')->label('Écart')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'heure inconnue' : "{$state} min")->placeholder('heure inconnue'),
                TextColumn::make('donnees.ressemblance')->label('Titres')
                    ->formatStateUsing(fn ($state): string => round($state * 100).' %'),
                TextColumn::make('decision.type')->label('Décision')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? TypeDecisionDedoublonnage::from($state)->getLabel() : null),
                TextColumn::make('statut')->badge()
                    ->color(fn (StatutElement $state): string => $state === StatutElement::EnAttente ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutElement::class)->default(StatutElement::EnAttente->value),
            ])
            ->recordActions([
                static::decision('fusionner', $fusionner, TypeDecisionDedoublonnage::Fusionner, Heroicon::OutlinedLink, 'success'),
                static::decision('separer', $separer, TypeDecisionDedoublonnage::Separer, Heroicon::OutlinedScissors, 'danger'),
            ])
            ->recordUrl(null);
    }

    /** Même séance / séances différentes (aussi dans la boîte de travail). */
    public static function decision(string $nom, string $libelle, TypeDecisionDedoublonnage $type, Heroicon $icone, string $couleur): Action
    {
        return Action::make($nom)->label($libelle)->icon($icone)->color($couleur)
            ->visible(fn (ElementATraiter $record): bool => $record->file === static::file() && $record->statut === StatutElement::EnAttente)
            ->requiresConfirmation()
            ->modalDescription('La décision est mémorisée et réappliquée à chaque collecte.')
            ->action(function (ElementATraiter $record) use ($type, $libelle): void {
                $a = static::offre($record, 'offre_a_id');
                $b = static::offre($record, 'offre_b_id');

                if ($a === null || $b === null) {
                    Notification::make()->danger()->title('Une des deux annonces n’existe plus.')->send();

                    return;
                }

                app(DeciderDoublon::class)->handle($a, $b, $type);
                Notification::make()->success()->title("Décision enregistrée : {$libelle}")->send();
            });
    }

    private static function colonneOffre(string $cle, string $libelle): TextColumn
    {
        return TextColumn::make($cle)->label($libelle)->wrap()
            ->state(fn (ElementATraiter $record): ?string => static::offre($record, $cle)?->donnees_normalisees['titre'])
            ->description(function (ElementATraiter $record) use ($cle): string {
                $offre = static::offre($record, $cle);

                if ($offre === null) {
                    return 'annonce supprimée';
                }

                $heure = $offre->debut?->setTimezone($offre->lieu?->fuseau_horaire ?? 'Europe/Paris');

                return collect([
                    $offre->source?->nom,
                    $heure?->format($offre->heure_connue ? 'd/m/Y H:i' : 'd/m/Y'),
                    $offre->prix_min !== null ? number_format((float) $offre->prix_min, 2, ',', ' ').' €' : null,
                ])->filter()->implode(' · ');
            });
    }

    private static function offre(ElementATraiter $record, string $cle): ?Offre
    {
        $id = $record->donnees[$cle] ?? null;

        return $id === null ? null : Offre::with(['source', 'lieu'])->find($id);
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
