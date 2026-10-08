<?php

namespace App\Filament\Resources\InscriptionsBeta;

use App\Filament\Resources\InscriptionsBeta\Pages\ListInscriptionsBeta;
use App\Models\InscriptionBeta;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Liste d'attente de la bêta (W05b) : seules les inscriptions confirmées par e-mail comptent ; export pour inviter les testeurs
 * (TestFlight, Google Play). Les inscriptions non confirmées disparaissent seules après 30 jours.
 */
class InscriptionBetaResource extends Resource
{
    protected static ?string $model = InscriptionBeta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Liste d’attente bêta';

    protected static ?string $modelLabel = 'inscription';

    protected static ?string $pluralModelLabel = 'liste d’attente bêta';

    protected static ?string $slug = 'liste-attente-beta';

    public static function getNavigationBadge(): ?string
    {
        $nombre = InscriptionBeta::whereNotNull('confirmee_le')->whereNull('invitee_le')->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->label('E-mail')->searchable()->copyable(),
                TextColumn::make('plateforme')->badge()->color('gray')->formatStateUsing(fn (string $state): string => InscriptionBeta::PLATEFORMES[$state] ?? $state),
                TextColumn::make('ville')->placeholder('—')->searchable(),
                TextColumn::make('created_at')->label('Inscrit le')->dateTime('d/m/Y H:i', 'Europe/Paris'),
                IconColumn::make('confirmee_le')->label('Confirmé')->boolean()->state(fn (InscriptionBeta $r): bool => $r->confirmee_le !== null),
                TextColumn::make('invitee_le')->label('Invité le')->date('d/m/Y')->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('confirmee')->label('Confirmées')->nullable()->attribute('confirmee_le')->default(true),
                SelectFilter::make('plateforme')->options(InscriptionBeta::PLATEFORMES),
                TernaryFilter::make('invitee')->label('Déjà invitées')->nullable()->attribute('invitee_le'),
            ])
            ->headerActions([
                Action::make('exporter')->label('Exporter les confirmées')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->action(fn (): StreamedResponse => static::exporter()),
            ])
            ->recordActions([DeleteAction::make()->label('Effacer')])
            ->toolbarActions([
                BulkAction::make('invitees')->label('Marquer comme invitées')->icon(Heroicon::OutlinedPaperAirplane)
                    ->action(function (Collection $lignes): void {
                        $lignes->each->update(['invitee_le' => now()]);
                        Notification::make()->success()->title($lignes->count().' inscription(s) marquée(s) comme invitée(s)')->send();
                    })->deselectRecordsAfterCompletion(),
            ]);
    }

    /** CSV des inscriptions confirmées (Excel, Numbers, ou import dans TestFlight). */
    public static function exporter(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $flux = fopen('php://output', 'w');
            fwrite($flux, "\u{FEFF}");
            fputcsv($flux, ['E-mail', 'Téléphone', 'Ville', 'Inscrit le', 'Confirmé le', 'Invité le'], ';');
            InscriptionBeta::whereNotNull('confirmee_le')->orderBy('created_at')->each(fn (InscriptionBeta $i) => fputcsv($flux, [
                $i->email, InscriptionBeta::PLATEFORMES[$i->plateforme] ?? $i->plateforme, $i->ville,
                $i->created_at->setTimezone('Europe/Paris')->format('d/m/Y'), $i->confirmee_le->setTimezone('Europe/Paris')->format('d/m/Y'), $i->invitee_le?->format('d/m/Y'),
            ], ';'));
            fclose($flux);
        }, 'spettacoli-liste-attente-beta-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListInscriptionsBeta::route('/')];
    }
}
