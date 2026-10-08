<?php

namespace App\Filament\Resources\Pistes;

use App\Contributions\Contributions;
use App\Enums\StatutPiste;
use App\Enums\TypePiste;
use App\Filament\Resources\Lieux\LieuResource;
use App\Filament\Resources\Pistes\Pages\ListPistes;
use App\Models\Lieu;
use App\Models\MotifRefus;
use App\Models\Piste;
use App\Models\Ville;
use App\Support\Texte;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * File « Pistes utilisateurs » (F8.5, F7.10) : une ligne par sujet (les pistes qui parlent de la même chose sont regroupées),
 * les plus demandées et celles des villes pilotes d'abord. Intégrer ou écarter répond à chaque personne du groupe (F8.6).
 */
class PisteResource extends Resource
{
    protected static ?string $model = Piste::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Contributions';

    protected static ?string $navigationLabel = 'Pistes utilisateurs';

    protected static ?string $modelLabel = 'piste';

    protected static ?string $pluralModelLabel = 'pistes utilisateurs';

    protected static ?string $slug = 'pistes';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->tetesDeGroupe()
            ->with(['ville', 'lieu', 'membres'])
            ->withCount('membres');
    }

    public static function getNavigationBadge(): ?string
    {
        $nombre = Piste::tetesDeGroupe()->whereIn('statut', StatutPiste::OUVERTS)->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            // Priorité (F8.5) : nombre de propositions, villes pilotes, ancienneté.
            ->defaultSort(fn (Builder $query) => $query
                ->orderByDesc('membres_count')
                ->orderByRaw('coalesce((select est_pilote from villes where villes.id = pistes.ville_id), false) desc')
                ->orderBy('created_at'))
            ->columns([
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('nom')->weight('bold')->wrap()->searchable()
                    ->description(fn (Piste $record): ?string => $record->membres->pluck('lien')->filter()->unique()->implode(' · ') ?: null),
                TextColumn::make('ville.nom')->label('Ville')->placeholder('—')
                    ->badge(fn (Piste $record): bool => (bool) $record->ville?->est_pilote)->color('info'),
                TextColumn::make('membres_count')->label('Proposé par')
                    ->formatStateUsing(fn (int $state): string => $state > 1 ? "{$state} personnes" : '1 personne'),
                TextColumn::make('lieu.nom')->label('Déjà couvert')->placeholder('—')->color('warning')
                    ->url(fn (Piste $record): ?string => $record->lieu ? LieuResource::getUrl('edit', ['record' => $record->lieu]) : null)
                    ->tooltip('Lieu déjà dans le catalogue : il manque peut-être seulement certains spectacles.'),
                TextColumn::make('commentaires')->label('Commentaires')->listWithLineBreaks()->wrap()->placeholder('—')
                    ->state(fn (Piste $record): array => $record->membres->pluck('commentaire')->filter()->values()->all()),
                IconColumn::make('travaille_pour_le_lieu')->label('Du lieu')->boolean()->trueIcon(Heroicon::OutlinedBuildingOffice)->falseIcon(null)
                    ->state(fn (Piste $record): bool => $record->membres->contains('travaille_pour_le_lieu', true))
                    ->tooltip('Quelqu’un qui travaille pour ce lieu a proposé la piste (candidat à un espace salle, F9).'),
                TextColumn::make('statut')->badge(),
                TextColumn::make('reponses')->label('Réponses')->placeholder('—')
                    ->state(fn (Piste $record): ?string => ($n = $record->membres->whereNotNull('reponse_envoyee_le')->count()) > 0 ? "{$n} e-mail(s)" : null)
                    ->tooltip(fn (Piste $record): ?string => $record->reponse),
                TextColumn::make('created_at')->label('Reçue le')->dateTime('d/m/Y', 'Europe/Paris'),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutPiste::class)->multiple()
                    ->default([StatutPiste::Nouvelle->value, StatutPiste::Etudiee->value]),
                SelectFilter::make('type')->options(TypePiste::class),
                // Vue par ville (F8.5).
                SelectFilter::make('ville_id')->label('Ville')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Ville::where('nom_normalise', 'like', Texte::normaliser($search).'%')
                        ->orderByDesc('est_pilote')->orderByDesc('population')->limit(20)->pluck('nom', 'id')->all())
                    ->options(fn (): array => Ville::where('est_pilote', true)->orderBy('nom')->pluck('nom', 'id')->all()),
            ])
            ->recordActions([
                Action::make('ouvrir')->label('Ouvrir le lien')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                    ->visible(fn (Piste $record): bool => filled($record->membres->pluck('lien')->filter()->first()))
                    ->url(fn (Piste $record): ?string => $record->membres->pluck('lien')->filter()->first(), shouldOpenInNewTab: true),
                Action::make('creerLieu')->label('Créer le lieu')->icon(Heroicon::OutlinedPlus)->color('gray')
                    ->visible(fn (Piste $record): bool => $record->statut->estOuvert() && $record->type === TypePiste::Salle && $record->lieu_id === null)
                    ->url(fn (): string => LieuResource::getUrl('create'), shouldOpenInNewTab: true),
                Action::make('etudier')->label('Étudiée')->icon(Heroicon::OutlinedMagnifyingGlass)->color('info')
                    ->visible(fn (Piste $record): bool => $record->statut === StatutPiste::Nouvelle)
                    ->action(function (Piste $record): void {
                        app(Contributions::class)->etudier($record, auth()->id());
                        Notification::make()->success()->title('Piste marquée « étudiée »')->send();
                    }),
                static::actionIntegrer(),
                static::actionEcarter(),
            ])
            ->recordUrl(null);
    }

    private static function actionIntegrer(): Action
    {
        return Action::make('integrer')->label('Intégrée')->icon(Heroicon::OutlinedCheck)->color('success')
            ->visible(fn (Piste $record): bool => $record->statut->estOuvert())
            ->modalHeading(fn (Piste $record): string => "« {$record->nom} » est maintenant dans Spettacoli")
            ->modalDescription(fn (Piste $record): string => static::destinataires($record))
            ->modalSubmitActionLabel('Envoyer la réponse')
            ->fillForm(fn (Piste $record): array => ['lieu_id' => $record->lieu_id])
            ->schema([
                Select::make('lieu_id')->label('Lieu du catalogue')->searchable()->placeholder('Aucun')
                    ->getSearchResultsUsing(fn (string $search): array => Lieu::with('ville')->where('nom_normalise', 'like', '%'.Texte::normaliser($search).'%')
                        ->whereNull('fusionne_dans_id')->limit(20)->get()
                        ->mapWithKeys(fn (Lieu $l) => [$l->id => "{$l->nom} ({$l->ville?->nom})"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($l = Lieu::with('ville')->find($value)) ? "{$l->nom} ({$l->ville?->nom})" : null),
                Textarea::make('message_personnel')->label('Phrase personnelle (facultative)')->rows(3)->maxLength(1000)
                    ->helperText('Ajoutée après « Bonne nouvelle : … est maintenant dans Spettacoli. »'),
            ])
            ->action(function (Piste $record, array $data): void {
                $envoyes = app(Contributions::class)->integrer($record, $data['lieu_id'] ?? null, $data['message_personnel'] ?? null, auth()->id());
                Notification::make()->success()->title("Piste intégrée — {$envoyes} e-mail(s) envoyé(s)")->send();
            });
    }

    private static function actionEcarter(): Action
    {
        return Action::make('ecarter')->label('Écarter')->icon(Heroicon::OutlinedXMark)->color('danger')
            ->visible(fn (Piste $record): bool => $record->statut->estOuvert())
            ->modalHeading(fn (Piste $record): string => "Écarter « {$record->nom} »")
            ->modalDescription(fn (Piste $record): string => static::destinataires($record))
            ->modalSubmitActionLabel('Envoyer la réponse')
            ->schema([
                Select::make('motif_refus_id')->label('Motif')->required()->live()
                    ->options(fn (): array => MotifRefus::orderBy('ordre')->pluck('libelle', 'id')->all())
                    ->helperText(fn (Get $get): ?string => ($m = MotifRefus::find($get('motif_refus_id'))) ? ($m->message_public ? "Message envoyé : « {$m->message_public} »" : 'Pas de message prévu : écrivez la réponse ci-dessous.') : null),
                Textarea::make('message_personnel')->label(fn (Get $get): string => static::motifSansMessage($get('motif_refus_id')) ? 'Réponse' : 'Phrase personnelle (facultative)')
                    ->rows(3)->maxLength(1000)
                    ->required(fn (Get $get): bool => static::motifSansMessage($get('motif_refus_id'))),
            ])
            ->action(function (Piste $record, array $data): void {
                $envoyes = app(Contributions::class)->ecarter($record, MotifRefus::findOrFail($data['motif_refus_id']), $data['message_personnel'] ?? null, auth()->id());
                Notification::make()->success()->title("Piste écartée — {$envoyes} e-mail(s) envoyé(s)")->send();
            });
    }

    private static function motifSansMessage(mixed $id): bool
    {
        return filled($id) && blank(MotifRefus::find($id)?->message_public);
    }

    /** Qui recevra la réponse : e-mail, « Mes propositions », ou personne. */
    private static function destinataires(Piste $record): string
    {
        $ouverts = $record->membres->filter(fn (Piste $p) => $p->statut->estOuvert());
        $emails = $ouverts->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e))->unique()->count();
        $comptes = $ouverts->whereNotNull('utilisateur_id')->count();

        return "Réponse envoyée par e-mail à {$emails} personne(s), visible dans « Mes propositions » pour {$comptes} compte(s)."
            .($emails + $comptes === 0 ? ' Personne n’a laissé de moyen de recevoir la réponse.' : '');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListPistes::route('/')];
    }
}
