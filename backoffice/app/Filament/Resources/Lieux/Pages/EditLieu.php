<?php

namespace App\Filament\Resources\Lieux\Pages;

use App\Actions\FusionnerLieux;
use App\Filament\Actions\ChercherAdresseBan;
use App\Filament\Resources\Lieux\LieuResource;
use App\Filament\Resources\Lieux\Pages\Concerns\ConvertitPosition;
use App\Models\Lieu;
use App\Models\Ville;
use Filament\Actions\Action;
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

    protected function getHeaderActions(): array
    {
        return [
            ChercherAdresseBan::make(fn (Lieu $record) => $record)
                ->after(fn () => $this->redirect(LieuResource::getUrl('edit', ['record' => $this->record]))), // formulaire à jour
            Action::make('fusionner')
                ->label('Fusionner avec un autre lieu')
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('gray')
                ->visible(fn (): bool => $this->record->fusionne_dans_id === null)
                ->modalDescription('Ce lieu est un doublon : il sera rattaché au lieu choisi, qui récupère les informations qui lui manquent. Ce lieu sera masqué.')
                ->schema([
                    LieuResource::champLieuAConserver(fn () => $this->record),
                ])
                ->action(function (array $data) {
                    $conserve = app(FusionnerLieux::class)->handle($this->record, Lieu::findOrFail($data['conserve_id']));

                    Notification::make()->success()->title('Lieux fusionnés')->send();

                    $this->redirect(LieuResource::getUrl('edit', ['record' => $conserve]));
                }),
        ];
    }
}
