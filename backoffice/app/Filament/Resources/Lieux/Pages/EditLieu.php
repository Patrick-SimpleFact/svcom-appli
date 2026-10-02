<?php

namespace App\Filament\Resources\Lieux\Pages;

use App\Actions\FusionnerLieux;
use App\Filament\Resources\Lieux\LieuResource;
use App\Filament\Resources\Lieux\Pages\Concerns\ConvertitPosition;
use App\Models\Lieu;
use App\Models\Ville;
use App\Support\Texte;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLieu extends EditRecord
{
    use ConvertitPosition;

    protected static string $resource = LieuResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->positionVersFormulaire($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->formulaireVersPosition($data);

        if (($data['ville_id'] ?? null) !== $this->record->ville_id) {
            $data['fuseau_horaire'] = Ville::find($data['ville_id'] ?? null)?->fuseau_horaire ?? $this->record->fuseau_horaire;
        }

        return $data;
    }

    private static function libelleLieu(Lieu $lieu): string
    {
        return $lieu->nom.($lieu->ville ? " — {$lieu->ville->nom}" : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fusionner')
                ->label('Fusionner avec un autre lieu')
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('gray')
                ->visible(fn (): bool => $this->record->fusionne_dans_id === null)
                ->modalDescription('Ce lieu est un doublon : il sera rattaché au lieu choisi, qui récupère les informations qui lui manquent. Ce lieu sera masqué.')
                ->schema([
                    Select::make('conserve_id')
                        ->label('Lieu à conserver')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Lieu::actifs()
                            ->whereKeyNot($this->record->getKey())
                            ->where('nom_normalise', 'like', '%'.Texte::normaliser($search).'%')
                            ->with('ville')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Lieu $lieu) => [$lieu->id => self::libelleLieu($lieu)])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($lieu = Lieu::find($value)) ? self::libelleLieu($lieu) : null),
                ])
                ->action(function (array $data) {
                    $conserve = app(FusionnerLieux::class)->handle($this->record, Lieu::findOrFail($data['conserve_id']));

                    Notification::make()->success()->title('Lieux fusionnés')->send();

                    $this->redirect(LieuResource::getUrl('edit', ['record' => $conserve]));
                }),
        ];
    }
}
