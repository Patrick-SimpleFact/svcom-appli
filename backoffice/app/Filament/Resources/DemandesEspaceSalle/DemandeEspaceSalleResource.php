<?php

namespace App\Filament\Resources\DemandesEspaceSalle;

use App\Enums\StatutDemandeSalle;
use App\Enums\TypeLieu;
use App\EspaceSalle\EspacesSalle;
use App\Filament\Resources\DemandesEspaceSalle\Pages\ListDemandesEspaceSalle;
use App\Filament\Resources\Lieux\LieuResource;
use App\Models\DemandeEspaceSalle;
use App\Models\Lieu;
use App\Support\Texte;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * File « Demandes d'espace salle » (F9.2) : valider (compte rattaché au lieu + invitation), refuser avec motif,
 * demander des précisions. Le lieu correspondant du référentiel est proposé automatiquement.
 */
class DemandeEspaceSalleResource extends Resource
{
    protected static ?string $model = DemandeEspaceSalle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Espace salle';

    protected static ?string $navigationLabel = 'Demandes';

    protected static ?string $modelLabel = 'demande d’espace salle';

    protected static ?string $pluralModelLabel = 'demandes d’espace salle';

    protected static ?string $slug = 'demandes-espace-salle';

    public static function getNavigationBadge(): ?string
    {
        $nombre = DemandeEspaceSalle::where('statut', StatutDemandeSalle::EnAttente)->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->modifyQueryUsing(fn ($query) => $query->with(['lieuPropose.ville', 'lieu', 'ville']))
            ->columns([
                TextColumn::make('nom_lieu')->label('Lieu demandé')->weight('bold')->wrap()
                    ->description(fn (DemandeEspaceSalle $r): string => collect([$r->adresse, trim($r->code_postal.' '.$r->ville_saisie)])->filter()->implode(', ')),
                TextColumn::make('lieuPropose.nom')->label('Lieu trouvé dans Spettacoli')->placeholder('Pas encore dans Spettacoli')->wrap()
                    ->url(fn (DemandeEspaceSalle $r): ?string => $r->lieuPropose ? LieuResource::getUrl('edit', ['record' => $r->lieuPropose]) : null, shouldOpenInNewTab: true),
                TextColumn::make('nom_demandeur')->label('Demandeur')->wrap()
                    ->description(fn (DemandeEspaceSalle $r): string => "{$r->fonction} · {$r->email} · {$r->telephone}"),
                TextColumn::make('details')->label('Détails')->wrap()->placeholder('—')
                    ->state(fn (DemandeEspaceSalle $r): ?string => collect([$r->site_web, $r->billetterie ? "Billetterie : {$r->billetterie}" : null, $r->message])->filter()->implode(' · ') ?: null),
                TextColumn::make('statut')->badge()
                    ->description(fn (DemandeEspaceSalle $r): ?string => $r->statut === StatutDemandeSalle::Refusee ? $r->motif_refus : ($r->statut === StatutDemandeSalle::PrecisionsDemandees ? $r->precisions_demandees : null)),
                TextColumn::make('created_at')->label('Reçue le')->dateTime('d/m/Y H:i', 'Europe/Paris'),
            ])
            ->filters([
                SelectFilter::make('statut')->options(StatutDemandeSalle::class)->multiple()
                    ->default([StatutDemandeSalle::EnAttente->value, StatutDemandeSalle::PrecisionsDemandees->value]),
            ])
            ->recordActions([static::actionValider(), static::actionPrecisions(), static::actionRefuser()])
            ->recordUrl(null);
    }

    private static function ouverte(DemandeEspaceSalle $r): bool
    {
        return in_array($r->statut, StatutDemandeSalle::OUVERTS, true);
    }

    public static function choixLieu(string $nom = 'lieu_id'): Select
    {
        return Select::make($nom)->label('Lieu dans Spettacoli')->required()->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Lieu::with('ville')->where('nom_normalise', 'like', '%'.Texte::normaliser($search).'%')
                ->whereNull('fusionne_dans_id')->limit(20)->get()->mapWithKeys(fn (Lieu $l) => [$l->id => "{$l->nom} ({$l->ville?->nom})"])->all())
            ->getOptionLabelUsing(fn ($value): ?string => ($l = Lieu::with('ville')->find($value)) ? "{$l->nom} ({$l->ville?->nom})" : null)
            ->helperText('Tapez une partie du nom (ex. « carnot »).');
    }

    private static function actionValider(): Action
    {
        return Action::make('valider')->label('Valider')->icon(Heroicon::OutlinedCheck)->color('success')
            ->visible(fn (DemandeEspaceSalle $r): bool => static::ouverte($r))
            ->modalHeading(fn (DemandeEspaceSalle $r): string => "Ouvrir l’espace de « {$r->nom_lieu} »")
            ->modalDescription(fn (DemandeEspaceSalle $r): string => "Le compte {$r->email} est créé (ou complété s’il existe déjà dans l’app), rattaché au lieu, et reçoit un e-mail d’invitation.")
            ->modalSubmitActionLabel('Valider et inviter')
            ->fillForm(fn (DemandeEspaceSalle $r): array => [
                'choix' => $r->lieu_propose_id ? 'existant' : 'nouveau', 'lieu_id' => $r->lieu_propose_id,
                'nom' => $r->nom_lieu, 'adresse' => $r->adresse, 'code_postal' => $r->code_postal, 'type' => TypeLieu::Theatre->value,
            ])
            ->schema(fn (DemandeEspaceSalle $r): array => [
                ToggleButtons::make('choix')->label('Le lieu')->inline()->live()->required()
                    ->options(['existant' => 'Déjà dans Spettacoli', 'nouveau' => 'Pas encore dans Spettacoli : le créer']),
                static::choixLieu()->visible(fn (Get $get): bool => $get('choix') === 'existant'),
                Grid::make(2)->visible(fn (Get $get): bool => $get('choix') === 'nouveau')->schema([
                    TextInput::make('nom')->label('Nom du lieu')->required()->maxLength(200)->columnSpanFull()
                        ->helperText(fn (): ?string => ($proches = static::lieuxProches($r)) === [] ? null
                            : '⚠️ Lieu(x) au nom proche dans cette ville : '.implode(' · ', $proches).'. Si c’est l’un d’eux, choisissez plutôt « Déjà dans Spettacoli ».'),
                    TextInput::make('adresse')->label('Adresse')->maxLength(255)
                        ->helperText('Sert à placer le lieu sur la carte (Base Adresse Nationale).'),
                    TextInput::make('code_postal')->label('Code postal')->maxLength(10),
                    Select::make('type')->label('Type')->options(TypeLieu::class)->required(),
                    TextInput::make('ville')->label('Ville')->disabled()->dehydrated(false)
                        ->formatStateUsing(fn (): string => $r->ville?->nom ?? "{$r->ville_saisie} (commune non reconnue)"),
                ]),
            ])
            ->action(function (DemandeEspaceSalle $r, array $data): void {
                $espaces = app(EspacesSalle::class);
                $lieuId = $data['choix'] === 'nouveau'
                    ? $espaces->creerLieu($r, $data['nom'], $data['adresse'] ?? null, $data['code_postal'] ?? null, $data['type'])->id
                    : (int) $data['lieu_id'];
                $espaces->valider($r, $lieuId, auth()->id());
                Notification::make()->success()->title('Espace ouvert, invitation envoyée')
                    ->body($data['choix'] === 'nouveau' ? 'Le lieu a été créé : vérifiez sa position dans Référentiels › Lieux.' : null)->send();
            });
    }

    /**
     * Garde-fou contre les doublons : les lieux de la commune (ou à 3 km de son centre) dont le nom partage un mot distinctif avec la demande
     * (« Carnot » dans « Théâtre Carnot »), les mots communs comme « théâtre » ou « salle » ne comptant pas.
     *
     * @return list<string>
     */
    public static function lieuxProches(DemandeEspaceSalle $r): array
    {
        $mots = array_values(array_diff(
            array_filter(explode(' ', Texte::normaliser($r->nom_lieu)), fn (string $m) => mb_strlen($m) >= 4),
            ['theatre', 'salle', 'espace', 'centre', 'culturel', 'compagnie', 'scene', 'maison', 'auditorium'],
        ));

        if ($mots === [] || $r->ville === null) {
            return [];
        }

        return Lieu::whereNull('fusionne_dans_id')
            ->where(fn ($q) => $q->where('ville_id', $r->ville_id)->orWhereRaw('ST_DWithin(position, ?::geography, 3000)', [$r->ville->position->versEwkt()]))
            ->where(fn ($q) => collect($mots)->each(fn (string $m) => $q->orWhereRaw('word_similarity(?, nom_normalise) >= 0.7', [$m])))
            ->limit(4)->pluck('nom')->all();
    }

    private static function actionPrecisions(): Action
    {
        return Action::make('precisions')->label('Demander des précisions')->icon(Heroicon::OutlinedQuestionMarkCircle)->color('info')
            ->visible(fn (DemandeEspaceSalle $r): bool => static::ouverte($r))
            ->modalSubmitActionLabel('Envoyer')
            ->schema([Textarea::make('question')->label('Votre question')->required()->rows(4)->maxLength(1000)
                ->helperText('Envoyée par e-mail au demandeur ; sa réponse vous arrivera directement.')])
            ->action(function (DemandeEspaceSalle $r, array $data): void {
                app(EspacesSalle::class)->demanderPrecisions($r, $data['question'], auth()->id(), auth()->user()?->email);
                Notification::make()->success()->title('Question envoyée')->send();
            });
    }

    private static function actionRefuser(): Action
    {
        return Action::make('refuser')->label('Refuser')->icon(Heroicon::OutlinedXMark)->color('danger')
            ->visible(fn (DemandeEspaceSalle $r): bool => static::ouverte($r))
            ->modalSubmitActionLabel('Refuser et prévenir')
            ->schema([Textarea::make('motif')->label('Motif (envoyé au demandeur)')->required()->rows(4)->maxLength(1000)])
            ->action(function (DemandeEspaceSalle $r, array $data): void {
                app(EspacesSalle::class)->refuser($r, $data['motif'], auth()->id());
                Notification::make()->success()->title('Demande refusée, e-mail envoyé')->send();
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListDemandesEspaceSalle::route('/')];
    }
}
