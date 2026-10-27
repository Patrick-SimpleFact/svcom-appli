<?php

namespace App\Filament\Resources\Spectacles\RelationManagers;

use App\Actions\PublierSource;
use App\Models\Offre;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Séances collectées rattachées au spectacle (K07), en attendant leur publication en représentations (K08).
 * Une ligne par annonce de billetterie : deux billetteries qui vendent la même séance donnent deux lignes du même groupe.
 * Toutes les annonces par défaut (demande de Patrick) ; un filtre isole les actives ou celles disparues des flux
 * (gardées 30 jours après la séance, K08b). Un lien retiré à la main (« Mes actions du jour ») se remet d'ici.
 */
class OffresRelationManager extends RelationManager
{
    protected static string $relationship = 'offres';

    protected static ?string $title = 'Séances collectées (avant publication)';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lieu.ville', 'source']))
            ->defaultSort(fn (Builder $query) => $query->orderBy('date_locale')->orderBy('debut'))
            ->paginated([25, 50, 'all'])
            ->columns([
                TextColumn::make('date_locale')->label('Jour')->date('D d/m/Y'),
                TextColumn::make('debut')->label('Heure')
                    ->state(fn (Offre $o): string => $o->heure_connue ? $o->debut->setTimezone($o->lieu?->fuseau_horaire ?? 'Europe/Paris')->format('H:i') : 'à confirmer'),
                TextColumn::make('lieu.nom')->label('Lieu')->wrap(),
                TextColumn::make('lieu.ville.nom')->label('Ville')->placeholder('—'),
                TextColumn::make('source.nom')->label('Billetterie'),
                TextColumn::make('prix')->label('Prix')
                    ->state(fn (Offre $o): string => RepresentationsRelationManager::prix($o->prix_min, $o->prix_max, (bool) ($o->donnees_normalisees['gratuit'] ?? false)))
                    ->description(fn (Offre $o): ?string => $o->complet ? 'Complet' : null),
                TextColumn::make('lien')->label('Réservation')
                    ->state(fn (Offre $o): ?string => $o->lien ? 'Réserver ↗' : null)->placeholder('—')
                    ->url(fn (Offre $o): ?string => $o->lien, shouldOpenInNewTab: true)
                    ->color('primary'),
                TextColumn::make('donnees_normalisees.titre')->label('Titre chez la source')->wrap(),
                TextColumn::make('disparue_le')->label('Disparue le')->dateTime('d/m/Y H:i', 'Europe/Paris')->placeholder('—')
                    ->description(fn (Offre $o): ?string => $o->masquee ? 'Lien retiré à la main' : null),
                TextColumn::make('seance')->label('Séance')
                    ->state(fn (Offre $o): string => 'n° '.($o->meme_seance_que_id ?? $o->id))
                    ->tooltip('Les lignes avec le même numéro sont la même séance, vendue par plusieurs billetteries.'),
            ])
            ->recordActions([
                Action::make('remettreLien')->label('Remettre le lien')->icon(Heroicon::OutlinedEye)->color('success')
                    ->visible(fn (Offre $o): bool => $o->masquee)
                    ->action(function (Offre $o): void {
                        // De nouveau en vente ; si la billetterie ne la vend plus, la collecte suivante la retirera.
                        $o->update(['masquee' => false, 'disparue_le' => null]);
                        app(PublierSource::class)->publierGroupes($o->newCollection([$o]));
                        Notification::make()->success()->title('Lien remis sur la séance')->send();
                    }),
            ])
            ->filters([
                SelectFilter::make('etat')->label('Annonces')
                    ->options(['actives' => 'Actives', 'disparues' => 'Disparues des flux'])
                    ->placeholder('Toutes') // option « sans filtre » de Filament, choisie par défaut
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'actives' => $query->whereNull('disparue_le'),
                        'disparues' => $query->whereNotNull('disparue_le'),
                        default => $query,
                    }),
            ]);
    }
}
