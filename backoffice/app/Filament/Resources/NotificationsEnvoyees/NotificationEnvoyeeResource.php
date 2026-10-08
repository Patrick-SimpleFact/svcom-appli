<?php

namespace App\Filament\Resources\NotificationsEnvoyees;

use App\Filament\Resources\NotificationsEnvoyees\Pages\ListNotificationsEnvoyees;
use App\Models\Appareil;
use App\Models\NotificationEnvoyee;
use App\Push\Apns;
use App\Push\EnvoiPush;
use App\Push\Fcm;
use App\Push\MessagePush;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Notifications envoyées (F3.7), en lecture seule : regroupement du soir et envois de test, ouverture, erreurs.
 * Bouton « Notification de test » pour vérifier Apple / Google sur un téléphone.
 */
class NotificationEnvoyeeResource extends Resource
{
    protected static ?string $model = NotificationEnvoyee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Statistiques';

    protected static ?string $navigationLabel = 'Notifications envoyées';

    protected static ?string $modelLabel = 'notification';

    protected static ?string $pluralModelLabel = 'notifications envoyées';

    protected static ?string $slug = 'notifications';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('appareil:id,identifiant,plateforme'))
            ->defaultSort('envoyee_le', 'desc')
            ->columns([
                TextColumn::make('envoyee_le')->label('Envoyée le')->dateTime('d/m/Y H:i', 'Europe/Paris'),
                TextColumn::make('titre')->weight('bold')->description(fn (NotificationEnvoyee $record): string => $record->corps)->wrap(),
                TextColumn::make('appareil.plateforme')->label('Téléphone')->badge()->color('gray'),
                TextColumn::make('nb_nouveautes')->label('Nouveautés'),
                TextColumn::make('resultat')->label('Résultat')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'envoyee' => 'Envoyée', 'jeton_invalide' => 'Jeton refusé', 'non_configure' => 'Clés absentes', default => 'Erreur'
                    })
                    ->color(fn (string $state): string => $state === 'envoyee' ? 'success' : ($state === 'erreur' ? 'danger' : 'warning'))
                    ->tooltip(fn (NotificationEnvoyee $record): ?string => $record->erreur),
                IconColumn::make('ouverte_le')->label('Ouverte')->boolean()->state(fn (NotificationEnvoyee $record): bool => $record->ouverte_le !== null),
                IconColumn::make('test')->boolean()->trueIcon(Heroicon::OutlinedBeaker)->falseIcon(null),
            ])
            ->filters([
                SelectFilter::make('resultat')->label('Résultat')->options(['envoyee' => 'Envoyée', 'jeton_invalide' => 'Jeton refusé', 'erreur' => 'Erreur', 'non_configure' => 'Clés absentes']),
                TernaryFilter::make('test')->label('Envois de test'),
            ])
            ->headerActions([static::actionTest()])
            ->recordUrl(null);
    }

    public static function actionTest(): Action
    {
        return Action::make('tester')->label('Notification de test')->icon(Heroicon::OutlinedPaperAirplane)
            ->modalDescription(fn (): string => 'Apple : '.(app(Apns::class)->configure() ? 'clés en place' : 'clés absentes').' · Google : '.(app(Fcm::class)->configure() ? 'clés en place' : 'clés absentes').'.')
            ->schema([
                Select::make('appareil_id')->label('Téléphone')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Appareil::with('utilisateur:id,email')->whereNotNull('jeton_push')
                        ->where(fn ($q) => $q->where('identifiant', 'ilike', "%{$search}%")->orWhereHas('utilisateur', fn ($u) => $u->where('email', 'ilike', "%{$search}%")))
                        ->latest('derniere_ouverture')->limit(20)->get()
                        ->mapWithKeys(fn (Appareil $a) => [$a->id => static::libelle($a)])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($a = Appareil::with('utilisateur:id,email')->find($value)) ? static::libelle($a) : null)
                    ->helperText('Seuls les téléphones qui ont autorisé les notifications (jeton reçu) sont proposés. Recherche par e-mail du compte ou identifiant.'),
                TextInput::make('texte')->label('Texte')->default('Test des notifications Spettacoli')->required()->maxLength(150),
            ])
            ->action(function (array $data): void {
                $note = app(EnvoiPush::class)->envoyer(Appareil::findOrFail($data['appareil_id']), fn (NotificationEnvoyee $n) => new MessagePush('Spettacoli', $data['texte'], null, ['ecran' => 'nouveautes', 'notification_id' => (string) $n->id]), ['test' => true]);
                $note->resultat === 'envoyee'
                    ? Notification::make()->success()->title('Notification envoyée')->send()
                    : Notification::make()->danger()->title('Non envoyée')->body($note->erreur)->send();
            });
    }

    private static function libelle(Appareil $a): string
    {
        return "{$a->plateforme} · ".($a->utilisateur?->email ?? $a->identifiant);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationsEnvoyees::route('/')];
    }
}
