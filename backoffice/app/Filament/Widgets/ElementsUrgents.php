<?php

namespace App\Filament\Widgets;

use App\Enums\FileATraiter;
use App\Enums\IssueFiltrage;
use App\Enums\StatutElement;
use App\Enums\TypeDecisionDedoublonnage;
use App\Filament\Pages\BoiteDeTravail;
use App\Filament\Resources\AClasser\AClasserResource;
use App\Filament\Resources\ATrier\ATrierResource;
use App\Filament\Resources\Doublons\DoublonProbableResource;
use App\Filament\Resources\Doublons\FusionAControlerResource;
use App\Filament\Resources\LieuxAVerifier\LieuAVerifierResource;
use App\Filament\Resources\SpectaclesAControler\SpectacleAControlerResource;
use App\Filament\Support\Urgence;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Source;
use App\Models\Spectacle;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Les éléments urgents de toutes les files, avec les mêmes boutons que dans chaque file : la décision s'applique tout de suite.
 */
class ElementsUrgents extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'À traiter en priorité';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        [$memeSeance, $seancesDifferentes] = DoublonProbableResource::libellesDecisions();
        [$confirmer, $separer] = FusionAControlerResource::libellesDecisions();

        return $table
            ->query(fn (): Builder => ElementATraiter::query()
                ->where('statut', StatutElement::EnAttente)
                ->whereIn('file', array_keys(BoiteDeTravail::RESSOURCES))
                ->with(['cible' => fn (MorphTo $morph) => $morph->morphWith([Offre::class => ['lieu.ville', 'source'], Lieu::class => ['ville']])]))
            ->defaultSort(fn (Builder $query) => Urgence::trier($query))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->columns([
                Urgence::colonneUrgence(),
                Urgence::colonneEcheance(),
                TextColumn::make('file')->label('File')->badge()->color('gray'),
                TextColumn::make('element')->label('Élément')->wrap()
                    ->state(fn (ElementATraiter $record): ?string => static::titre($record))
                    ->description(fn (ElementATraiter $record): ?string => static::details($record)),
            ])
            ->filters([
                SelectFilter::make('urgence')->label('Urgence')
                    ->options(Urgence::LIBELLES)
                    ->default('2')
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('urgence', '>=', (int) $data['value']) : $query),
                SelectFilter::make('file')->label('File')
                    ->options(collect(BoiteDeTravail::RESSOURCES)->keys()->mapWithKeys(fn (string $f) => [$f => FileATraiter::from($f)->getLabel()])->all()),
            ])
            ->recordActions([
                ATrierResource::decision('garder', 'Garder', IssueFiltrage::Garde, 'success', Heroicon::OutlinedCheck),
                ATrierResource::decision('exclure', 'Exclure', IssueFiltrage::Exclu, 'danger', Heroicon::OutlinedXMark),
                ATrierResource::actionAjouterMot(),
                DoublonProbableResource::decision('memeSeance', $memeSeance, TypeDecisionDedoublonnage::Fusionner, Heroicon::OutlinedLink, 'success'),
                DoublonProbableResource::decision('seancesDifferentes', $seancesDifferentes, TypeDecisionDedoublonnage::Separer, Heroicon::OutlinedScissors, 'danger'),
                FusionAControlerResource::decision('confirmerFusion', $confirmer, TypeDecisionDedoublonnage::Fusionner, Heroicon::OutlinedLink, 'success'),
                FusionAControlerResource::decision('separerFusion', $separer, TypeDecisionDedoublonnage::Separer, Heroicon::OutlinedScissors, 'danger'),
                AClasserResource::actionClasser(),
                LieuAVerifierResource::actionChercherBan(),
                LieuAVerifierResource::actionRattacher(),
                LieuAVerifierResource::actionVerifie(),
                SpectacleAControlerResource::actionConfirmer(),
                SpectacleAControlerResource::actionSeparer(),
                Action::make('ouvrirFile')->label('Ouvrir la file')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->url(fn (ElementATraiter $record): string => BoiteDeTravail::RESSOURCES[$record->file->value]::getUrl()),
            ])
            ->emptyStateHeading('Rien d’urgent')
            ->emptyStateDescription('Changez le filtre « Urgence » pour voir le reste.')
            ->recordUrl(null);
    }

    /** Ce dont il s'agit, quelle que soit la file. */
    private static function titre(ElementATraiter $record): ?string
    {
        $cible = $record->cible;

        return match ($record->file) {
            FileATraiter::ATrier => $record->donnees['titre'] ?? null,
            FileATraiter::AClasser => '« '.($record->donnees['categorie'] ?? '?').' »',
            FileATraiter::DoublonProbable, FileATraiter::FusionAControler => $cible instanceof Offre ? $cible->donnees_normalisees['titre'] ?? null : 'annonce supprimée',
            FileATraiter::LieuAVerifier => $cible instanceof Lieu ? $cible->nom : 'lieu supprimé',
            FileATraiter::SpectacleAControler => $cible instanceof Spectacle ? $cible->titre : 'spectacle supprimé',
            default => null,
        };
    }

    private static function details(ElementATraiter $record): ?string
    {
        $cible = $record->cible;
        $d = $record->donnees ?? [];

        return match ($record->file) {
            FileATraiter::ATrier => collect([$d['lieu'] ?? null, $d['ville'] ?? null, 'score '.($d['score'] ?? '?')])->filter()->implode(' · '),
            FileATraiter::AClasser => collect([$cible instanceof Source ? $cible->nom : null, ($d['nb_annonces'] ?? 0).' annonce(s)'])->filter()->implode(' · '),
            FileATraiter::DoublonProbable, FileATraiter::FusionAControler => $cible instanceof Offre
                ? collect([$cible->lieu?->nom, $cible->lieu?->ville?->nom, isset($d['ecart_minutes']) ? "écart {$d['ecart_minutes']} min" : null, isset($d['ressemblance']) ? 'titres à '.round($d['ressemblance'] * 100).' %' : null])->filter()->implode(' · ')
                : null,
            FileATraiter::LieuAVerifier => collect([$cible instanceof Lieu ? $cible->ville?->nom : null, $d['motif'] ?? null])->filter()->implode(' · '),
            FileATraiter::SpectacleAControler => $d['motif'] ?? null,
            default => null,
        };
    }
}
