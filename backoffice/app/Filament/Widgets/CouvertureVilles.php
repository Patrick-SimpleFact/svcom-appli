<?php

namespace App\Filament\Widgets;

use App\Models\Couverture;
use App\Models\Parametre;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * Dernière mesure de chaque ville pilote, et son évolution sur 7 jours (F7.13).
 */
class CouvertureVilles extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    /** Redessiné quand la page relance le calcul (bouton de l'en-tête). */
    #[On('couverture-mesuree')]
    public function rafraichir(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->heading('Spectacles visibles dans l’app, à '.Parametre::valeur('couverture_rayon_km').' km du centre de chaque ville pilote')
            ->query(fn (): Builder => Couverture::query()->with('ville')
                ->whereIn('id', Couverture::query()->whereHas('ville', fn ($q) => $q->where('est_pilote', true))
                    ->selectRaw('max(id)')->groupBy('ville_id')))
            ->defaultSort('trente_jours', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('ville.nom')->label('Ville')->weight('bold'),
                static::nombre('ce_soir', 'Ce soir'),
                static::nombre('week_end', 'Ce week-end'),
                static::nombre('trente_jours', '30 jours'),
                static::nombre('spectacles', 'Spectacles différents (30 j)'),
                TextColumn::make('avec_horaire_pct')->label('Avec horaire')->suffix(' %')->placeholder('—')
                    ->color(fn (?int $state): string => $state !== null && $state < 80 ? 'warning' : 'gray'),
                TextColumn::make('mesuree_le')->label('Mesurée')->since('Europe/Paris')
                    ->tooltip(fn (Couverture $record): string => $record->mesuree_le->setTimezone('Europe/Paris')->format('d/m/Y H:i')),
            ])
            ->emptyStateHeading('Pas encore de mesure')
            ->emptyStateDescription('Cliquez sur « Mesurer maintenant » (puis automatique toutes les heures).');
    }

    /** Un chiffre, avec l'écart par rapport à la mesure d'il y a 7 jours quand elle existe. */
    private static function nombre(string $champ, string $libelle): TextColumn
    {
        return TextColumn::make($champ)->label($libelle)->numeric(locale: 'fr')->sortable()
            ->description(function (Couverture $record) use ($champ): ?string {
                $avant = Couverture::where('ville_id', $record->ville_id)->whereDate('jour', $record->jour->copy()->subDays(7))->value($champ);

                if ($avant === null || $avant === 0) {
                    return null;
                }

                return sprintf('%+d %% sur 7 j', round(100 * ($record->{$champ} - $avant) / $avant));
            });
    }
}
