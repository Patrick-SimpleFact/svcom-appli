<?php

namespace App\Models\Concerns;

use App\Actions\EnregistrerActionJournal;
use App\Enums\ActionJournal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * À ajouter sur tout modèle modifiable à la main dans le back-office :
 * chaque création, modification ou suppression par un admin est journalisée.
 */
trait Journalise
{
    public static function bootJournalise(): void
    {
        static::created(function (Model $modele) {
            app(EnregistrerActionJournal::class)->handle(ActionJournal::Creation, $modele, null, $modele->getAttributes());
        });

        static::updated(function (Model $modele) {
            $modifies = $modele->getChanges();

            app(EnregistrerActionJournal::class)->handle(
                ActionJournal::Modification,
                $modele,
                Arr::only($modele->getOriginal(), array_keys($modifies)),
                $modifies,
            );
        });

        static::deleted(function (Model $modele) {
            app(EnregistrerActionJournal::class)->handle(ActionJournal::Suppression, $modele, $modele->getOriginal(), null);
        });
    }
}
