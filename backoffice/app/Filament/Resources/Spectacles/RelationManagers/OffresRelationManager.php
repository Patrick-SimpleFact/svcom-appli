<?php

namespace App\Filament\Resources\Spectacles\RelationManagers;

use App\Models\Offre;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Séances collectées rattachées au spectacle (K07), en attendant leur publication en représentations (K08).
 * Une ligne par annonce de billetterie : deux billetteries qui vendent la même séance donnent deux lignes du même groupe.
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
                TextColumn::make('donnees_normalisees.titre')->label('Titre chez la source')->wrap(),
                TextColumn::make('seance')->label('Séance')
                    ->state(fn (Offre $o): string => 'n° '.($o->meme_seance_que_id ?? $o->id))
                    ->tooltip('Les lignes avec le même numéro sont la même séance, vendue par plusieurs billetteries.'),
            ]);
    }
}
