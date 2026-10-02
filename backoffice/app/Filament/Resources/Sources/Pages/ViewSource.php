<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Collecte\RegistreConnecteurs;
use App\Filament\Resources\Collectes\CollecteResource;
use App\Filament\Resources\Sources\SourceResource;
use App\Jobs\CollecterSource;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSource extends ViewRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        $connecteurEcrit = app(RegistreConnecteurs::class)->existe($this->record);

        return [
            Action::make('lancer')
                ->label('Lancer la collecte maintenant')
                ->icon('heroicon-o-play')
                ->disabled(! $connecteurEcrit)
                ->tooltip($connecteurEcrit ? null : 'Le connecteur de cette source n’est pas encore écrit (bloc 3).')
                ->schema(fn (): array => $this->record->code === 'factice' ? [
                    Toggle::make('simuler_echec')->label('Simuler un échec (source indisponible)')->default($this->record->config['simuler_echec'] ?? false),
                ] : [])
                ->action(function (array $data) {
                    if (array_key_exists('simuler_echec', $data)) {
                        $this->record->update(['config' => [...($this->record->config ?? []), 'simuler_echec' => $data['simuler_echec']]]);
                    }

                    CollecterSource::dispatch($this->record);

                    Notification::make()->success()->title('Collecte lancée')
                        ->body('Suivez-la dans Collecte › Collectes.')
                        ->send();

                    $this->redirect(CollecteResource::getUrl('index'));
                }),
        ];
    }
}
