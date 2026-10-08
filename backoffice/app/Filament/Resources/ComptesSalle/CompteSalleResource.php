<?php

namespace App\Filament\Resources\ComptesSalle;

use App\EspaceSalle\EspacesSalle;
use App\Filament\Resources\ComptesSalle\Pages\ListComptesSalle;
use App\Filament\Resources\DemandesEspaceSalle\DemandeEspaceSalleResource;
use App\Models\StatutUtilisateur;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Comptes des théâtres (F9.2) : qui gère quel lieu. Le super-admin peut créer un compte directement (théâtre démarché
 * par téléphone), renvoyer l'invitation, et retirer l'accès à tout moment (le compte de l'app reste).
 */
class CompteSalleResource extends Resource
{
    protected static ?string $model = StatutUtilisateur::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Espace salle';

    protected static ?string $navigationLabel = 'Comptes des théâtres';

    protected static ?string $modelLabel = 'compte de théâtre';

    protected static ?string $pluralModelLabel = 'comptes des théâtres';

    protected static ?string $slug = 'comptes-theatres';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('statut', StatutUtilisateur::GESTIONNAIRE_LIEU)->with(['utilisateur', 'lieu.ville']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('accorde_le', 'desc')
            ->columns([
                TextColumn::make('lieu.nom')->label('Lieu')->weight('bold')->description(fn (StatutUtilisateur $r): ?string => $r->lieu?->ville?->nom),
                TextColumn::make('utilisateur.email')->label('E-mail')->searchable(),
                TextColumn::make('utilisateur.derniere_connexion')->label('Dernière connexion')->dateTime('d/m/Y H:i', 'Europe/Paris')->placeholder('Jamais'),
                TextColumn::make('accorde_le')->label('Ouvert le')->dateTime('d/m/Y', 'Europe/Paris'),
            ])
            ->headerActions([
                Action::make('creer')->label('Créer un compte')->icon(Heroicon::OutlinedPlus)
                    ->modalDescription('Pour un théâtre démarché directement : le compte est rattaché au lieu et reçoit l’invitation par e-mail.')
                    ->schema([TextInput::make('email')->label('E-mail')->email()->required(), DemandeEspaceSalleResource::choixLieu()])
                    ->action(function (array $data): void {
                        app(EspacesSalle::class)->creerCompte($data['email'], (int) $data['lieu_id'], auth()->id());
                        Notification::make()->success()->title('Compte créé, invitation envoyée')->send();
                    }),
            ])
            ->recordActions([
                Action::make('reinviter')->label('Renvoyer l’invitation')->icon(Heroicon::OutlinedEnvelope)->color('gray')
                    ->requiresConfirmation()
                    ->action(function (StatutUtilisateur $r): void {
                        app(EspacesSalle::class)->creerCompte($r->utilisateur->email, $r->lieu_id, auth()->id());
                        Notification::make()->success()->title('Invitation renvoyée')->send();
                    }),
                Action::make('desactiver')->label('Retirer l’accès')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->requiresConfirmation()->modalDescription('La personne ne peut plus ouvrir l’espace de ce lieu. Son compte de l’app reste.')
                    ->action(function (StatutUtilisateur $r): void {
                        app(EspacesSalle::class)->desactiver($r);
                        Notification::make()->success()->title('Accès retiré')->send();
                    }),
            ])
            ->recordUrl(null);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListComptesSalle::route('/')];
    }
}
