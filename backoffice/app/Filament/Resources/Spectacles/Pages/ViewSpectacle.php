<?php

namespace App\Filament\Resources\Spectacles\Pages;

use App\Actions\FusionnerSpectacles;
use App\Filament\Resources\Spectacles\SpectacleResource;
use App\Filament\Support\Masquage;
use App\Models\Spectacle;
use App\Support\Texte;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSpectacle extends ViewRecord
{
    protected static string $resource = SpectacleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Corriger'),
            $this->fusionner(),
            ...Masquage::boutons('masque', 'Le spectacle et toutes ses séances disparaissent de l’app.'),
        ];
    }

    /** Deux fiches pour le même spectacle : celle-ci rejoint le spectacle choisi, qui garde son titre et ses corrections. */
    private function fusionner(): Action
    {
        return Action::make('fusionner')->label('Fusionner avec…')->icon(Heroicon::OutlinedArrowsPointingIn)->color('warning')
            ->modalHeading('Fusionner ce spectacle avec un autre')
            ->modalDescription('Toutes les séances de cette fiche passent au spectacle choisi, qui garde son titre, sa description et ses corrections. Les favoris suivent. Cette fiche, vide, disparaît ensuite du catalogue. Irréversible.')
            ->schema([
                Select::make('garde')->label('Spectacle à garder')->required()->searchable()
                    ->helperText('Tapez une partie du titre ou le numéro du spectacle.')
                    ->getSearchResultsUsing(fn (string $search): array => Spectacle::query()
                        ->whereKeyNot($this->getRecord()->getKey())
                        ->where(fn ($q) => ctype_digit(trim($search))
                            ? $q->whereKey((int) trim($search))
                            : $q->where('titre_normalise', 'like', '%'.Texte::normaliser($search).'%'))
                        ->orderBy('titre')->limit(30)->get()
                        ->mapWithKeys(fn (Spectacle $s): array => [$s->id => self::libelle($s)])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($s = Spectacle::find($value)) ? self::libelle($s) : null),
            ])
            ->action(function (array $data, FusionnerSpectacles $fusion): void {
                $garde = Spectacle::findOrFail($data['garde']);
                $fusion->handle($this->getRecord(), $garde);
                Notification::make()->success()->title('Spectacles fusionnés')->body('Les séances sont maintenant sur « '.$garde->titre.' ».')->send();
                $this->redirect(SpectacleResource::getUrl('view', ['record' => $garde]));
            });
    }

    /** « Titre (n° 8672 — Lieu, Ville) » : le lieu de la prochaine date distingue les homonymes. */
    private static function libelle(Spectacle $s): string
    {
        $lieu = $s->representations()->whereDate('date_locale', '>=', today())->with('lieu.ville')->orderBy('date_locale')->first()?->lieu;

        return $s->titre.' (n° '.$s->id.($lieu ? ' — '.$lieu->nom.($lieu->ville ? ', '.$lieu->ville->nom : '') : '').')';
    }
}
