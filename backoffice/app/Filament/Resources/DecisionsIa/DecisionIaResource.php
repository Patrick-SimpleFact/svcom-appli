<?php

namespace App\Filament\Resources\DecisionsIa;

use App\Enums\FileATraiter;
use App\Enums\StatutPropositionIa;
use App\Filament\Resources\DecisionsIa\Pages\ListDecisionsIa;
use App\Filament\Support\OuvertSelonProfil;
use App\Ia\AnnulerDecision;
use App\Ia\Assistant;
use App\Models\Offre;
use App\Models\PropositionIa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use UnitEnum;

/**
 * « Décisions de l'IA » (plan-module-ia v2) : ce que la cascade a décidé, appliqué ou non, et un bouton pour annuler
 * une décision appliquée. Pour relire chaque jour ce que l'assistant a fait à votre place.
 */
class DecisionIaResource extends Resource
{
    use OuvertSelonProfil;

    protected static ?string $model = PropositionIa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'Décisions de l’IA';

    protected static ?string $modelLabel = 'décision de l’IA';

    protected static ?string $pluralModelLabel = 'décisions de l’IA';

    protected static ?string $slug = 'decisions-ia';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('modele', 'cascade')
            ->whereIn('statut', [StatutPropositionIa::Appliquee, StatutPropositionIa::Proposee, StatutPropositionIa::Annulee])
            ->with('element.cible');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('updated_at')->label('Le')->dateTime('d/m H:i', 'Europe/Paris')->sortable(),
                TextColumn::make('file')->label('File')->badge()->color('gray'),
                TextColumn::make('element')->label('Élément')->wrap()
                    ->state(fn (PropositionIa $record): ?string => self::element($record))
                    ->url(fn (PropositionIa $record): ?string => self::lienBilletterie($record), shouldOpenInNewTab: true)
                    ->tooltip('Voir chez la billetterie'),
                TextColumn::make('decision')->label('Décision')->wrap()
                    ->state(fn (PropositionIa $record): string => app(Assistant::class)->tache($record->file)->libelle($record))
                    ->description(fn (PropositionIa $record): ?string => $record->justification),
                TextColumn::make('niveau')->label('Niveau')->formatStateUsing(fn (?int $state): string => $state === 2 ? '2e (un modèle)' : '1er (deux modèles)'),
                TextColumn::make('confiance')->label('Confiance')->suffix(' %'),
                TextColumn::make('statut')->badge(),
            ])
            ->filters([
                SelectFilter::make('statut')->options([
                    StatutPropositionIa::Appliquee->value => 'Appliquée',
                    StatutPropositionIa::Proposee->value => 'Proposée (observation)',
                    StatutPropositionIa::Annulee->value => 'Annulée',
                ])->default(StatutPropositionIa::Appliquee->value),
                SelectFilter::make('file')->label('File')->options(collect(Assistant::TACHES)->keys()
                    ->mapWithKeys(fn (string $f) => [$f => FileATraiter::from($f)->getLabel()])->all()),
            ])
            ->recordActions([
                Action::make('annuler')->label('Annuler')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                    ->visible(fn (PropositionIa $record): bool => $record->statut === StatutPropositionIa::Appliquee)
                    ->requiresConfirmation()
                    ->modalDescription(fn (PropositionIa $record): string => in_array($record->file, [FileATraiter::DoublonProbable, FileATraiter::FusionAControler], true)
                        ? 'La décision inverse sera enregistrée et appliquée tout de suite (une décision de dédoublonnage est mémorisée pour les collectes).'
                        : 'L’élément reviendra dans « Mes actions du jour » pour que vous décidiez vous-même.')
                    ->action(function (PropositionIa $record): void {
                        try {
                            Notification::make()->success()->title(app(AnnulerDecision::class)->handle($record))->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title('Annulation impossible')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->recordUrl(null);
    }

    private static function element(PropositionIa $record): ?string
    {
        $d = $record->element?->donnees ?? [];

        return match ($record->file) {
            FileATraiter::AClasser => ($record->element?->cible?->nom ?? '?').' · « '.($d['categorie'] ?? '?').' »',
            FileATraiter::SpectacleAControler => $record->element?->cible?->titre ?? ($d['titre'] ?? null),
            FileATraiter::DoublonProbable, FileATraiter::FusionAControler => collect([$d['offre_a_id'] ?? 0, $d['offre_b_id'] ?? 0])
                ->map(fn ($id) => Offre::with('source')->find($id))
                ->map(fn (?Offre $o) => $o ? ($o->donnees_normalisees['titre'] ?? '?').' ('.$o->source?->nom.', '.$o->debut?->setTimezone('Europe/Paris')->format('d/m H:i').')' : '?')
                ->implode(' / '),
            default => $d['titre'] ?? null,
        };
    }

    /** L'annonce chez la billetterie, pour vérifier la décision d'un clic. */
    private static function lienBilletterie(PropositionIa $record): ?string
    {
        $element = $record->element;
        $d = $element?->donnees ?? [];

        return match ($record->file) {
            FileATraiter::ATrier => $d['lien'] ?? null,
            FileATraiter::DoublonProbable, FileATraiter::FusionAControler => Offre::find($d['offre_a_id'] ?? 0)?->lien,
            FileATraiter::SpectacleAControler => Offre::where('spectacle_id', $element?->cible_id)->whereNotNull('lien')->value('lien'),
            FileATraiter::AClasser => Offre::where('source_id', $element?->cible_id)->whereNotNull('lien')
                ->whereJsonContains('donnees_normalisees->categories_source', $d['categorie'] ?? '')->latest('id')->value('lien'),
            default => null,
        };
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListDecisionsIa::route('/')];
    }
}
