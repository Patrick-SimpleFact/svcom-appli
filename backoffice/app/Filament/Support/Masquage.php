<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Boutons « Masquer » / « Réafficher » d'une fiche (spectacle, lieu, source) : immédiats et réversibles (F7.8).
 */
class Masquage
{
    /** @return array{Action, Action} */
    public static function boutons(string $attribut, string $effet): array
    {
        return [
            Action::make('masquer')->label('Masquer dans l’app')->icon(Heroicon::OutlinedEyeSlash)->color('danger')
                ->visible(fn (Model $record): bool => ! $record->{$attribut})
                ->requiresConfirmation()
                ->modalDescription($effet.' Tout de suite, et réversible avec « Réafficher ».')
                ->action(function (Model $record) use ($attribut): void {
                    $record->update([$attribut => true]);
                    Notification::make()->success()->title('Masqué dans l’app')->send();
                }),
            Action::make('reafficher')->label('Réafficher')->icon(Heroicon::OutlinedEye)->color('success')
                ->visible(fn (Model $record): bool => (bool) $record->{$attribut})
                ->action(function (Model $record) use ($attribut): void {
                    $record->update([$attribut => false]);
                    Notification::make()->success()->title('Réaffiché dans l’app')->send();
                }),
        ];
    }
}
