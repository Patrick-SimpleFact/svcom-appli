<?php

namespace App\Filament\Resources\Sources;

use App\Enums\StatutCollecte;
use App\Filament\Resources\Collectes\CollecteResource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\Pages\ViewSource;
use App\Jobs\CollecterSource;
use App\Models\Alerte;
use App\Models\Source;
use App\Support\SupervisionSources;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Sources déclarées (F7.3). Une source se désactive d'un clic ; ses événements disparaissent à la publication suivante.
 */
class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $modelLabel = 'source';

    protected static ?string $pluralModelLabel = 'sources';

    protected static ?string $recordTitleAttribute = 'nom';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('nom'),
            TextEntry::make('code'),
            TextEntry::make('type_acces')->label('Accès')->badge(),
            TextEntry::make('type_lien')->label('Lien de réservation')->badge(),
            TextEntry::make('licence'),
            TextEntry::make('mention_obligatoire')->label('Mention obligatoire')->placeholder('Aucune'),
            IconEntry::make('actif')->label('Collecte active')->boolean(),
            IconEntry::make('masquee')->label('Masquée dans l’app')->boolean()->trueColor('danger')->falseColor('gray'),
            TextEntry::make('zone')->label('Zone')->state(fn (Source $record): string => $record->zone ? implode(', ', $record->zone['villes'] ?? []) : 'Toute la France'),
            TextEntry::make('derniere_verification_le')->label('Dernière vérification')->dateTime('d/m/Y H:i', 'Europe/Paris')->placeholder('Jamais'),
            TextEntry::make('prochain_controle')->label('Prochain contrôle')
                ->state(fn (Source $record): string => $record->actif ? self::prochainControle()->format('d/m/Y H:i') : 'Source désactivée'),
            TextEntry::make('derniere_version_vue')->label('Dernière version vue à la source')->placeholder('—'),
            TextEntry::make('erreur_detection')->label('Erreur de détection')->color('danger')->placeholder('Aucune'),
            TextEntry::make('remarques')->columnSpanFull()->placeholder('—'),
            KeyValueEntry::make('fiabilite')->label('Fiabilité par champ'),
            KeyValueEntry::make('config')->label('Paramètres du connecteur')->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->modifyQueryUsing(fn ($query) => $query->withCount(['alertes as alertes_ouvertes' => fn ($q) => $q->whereNull('resolue_le')]))
            ->columns([
                TextColumn::make('nom')->weight('bold')
                    ->description(fn (Source $record): string => $record->type_acces->getLabel().' · lien '.$record->type_lien->getLabel()),
                TextColumn::make('alertes_ouvertes')->label('Alertes')->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} ouverte(s)" : 'OK')
                    ->tooltip(fn (Source $record): ?string => $record->alertes()->ouvertes()->get()
                        ->map(fn (Alerte $a) => $a->type->getLabel().' : '.$a->message)->implode("\n") ?: null),
                TextColumn::make('derniere_collecte')->label('Dernière collecte')
                    ->state(function (Source $record): ?string {
                        $c = SupervisionSources::derniereCollecte($record->id);

                        return $c ? match ($c->statut) {
                            StatutCollecte::Reussie => '✅ ', StatutCollecte::EnCours => '⏳ ', StatutCollecte::Echouee => '⚠️ ', StatutCollecte::Abandonnee => '❌ ',
                        }.$c->debut->setTimezone('Europe/Paris')->format('d/m H:i') : null;
                    })
                    ->description(function (Source $record): ?string {
                        $c = SupervisionSources::derniereCollecte($record->id);

                        return $c?->fin ? 'durée '.$c->debut->diffForHumans($c->fin, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) : null;
                    })
                    ->placeholder('Jamais'),
                TextColumn::make('volumes')->label('Reçues → retenues → nouvelles → retirées')
                    ->state(function (Source $record): ?string {
                        $c = SupervisionSources::volumes($record->id)['collecte'];

                        return $c ? collect([$c->nb_recus, $c->nb_retenus, $c->nb_nouveaux, $c->nb_retires])->map(fn ($n) => number_format($n, 0, ',', ' '))->implode(' → ') : null;
                    })
                    ->description(function (Source $record): ?string {
                        $variation = SupervisionSources::volumes($record->id)['variation_pct'];

                        return $variation === null ? 'pas assez d’historique (7 j)' : sprintf('%+d %% / moyenne 7 j', $variation);
                    })
                    ->placeholder('—'),
                TextColumn::make('qualite')->label('Qualité (à venir)')
                    ->state(fn (Source $record): ?string => ($q = SupervisionSources::qualite($record->id))
                        ? "horaire {$q['horaire']} % · géoloc. {$q['geoloc']} % · genre {$q['genre']} %" : null)
                    ->description(fn (Source $record): ?string => ($q = SupervisionSources::qualite($record->id)) ? number_format($q['offres'], 0, ',', ' ').' séances vendues' : null)
                    ->placeholder('—'),
                TextColumn::make('derniere_verification_le')->label('Dernière vérification')
                    ->dateTime('d/m H:i', 'Europe/Paris')->placeholder('Jamais')
                    ->description(fn (Source $record): ?string => $record->erreur_detection ? '⚠️ '.mb_strimwidth($record->erreur_detection, 0, 60, '…') : null),
                ToggleColumn::make('actif')->label('Active'),
                IconColumn::make('masquee')->label('Masquée')->boolean()->trueIcon(Heroicon::OutlinedEyeSlash)->trueColor('danger')->falseIcon(''),
            ])
            ->recordActions([
                Action::make('relancer')->label('Relancer')->icon(Heroicon::OutlinedPlay)->color('gray')
                    ->visible(fn (Source $record): bool => $record->actif)
                    ->requiresConfirmation()
                    ->action(function (Source $record): void {
                        CollecterSource::dispatch($record);
                        Notification::make()->success()->title('Collecte lancée')->body('Suivez-la dans Collecte › Collectes.')->send();
                    }),
                Action::make('erreurs')->label('Collectes')->icon(Heroicon::OutlinedListBullet)->color('gray')
                    ->url(fn (Source $record): string => CollecteResource::getUrl('index', ['filters' => ['source_id' => ['value' => $record->id]]])),
                ViewAction::make(),
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $nombre = Alerte::ouvertes()->whereHas('source', fn ($q) => $q->where('actif', true))->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** Le détecteur passe toutes les 30 min (à l'heure et à la demie). */
    public static function prochainControle(): CarbonInterface
    {
        $maintenant = now('Europe/Paris')->startOfMinute();

        return $maintenant->minute < 30
            ? $maintenant->setTime($maintenant->hour, 30)
            : $maintenant->addHour()->setTime($maintenant->hour, 0);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'view' => ViewSource::route('/{record}'),
        ];
    }
}
