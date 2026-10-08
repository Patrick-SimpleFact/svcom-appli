<?php

namespace App\Console\Commands;

use App\Actions\EnvoyerNotificationsDuSoir;
use Illuminate\Console\Command;

/** Notification regroupée du soir (F3.4) : à 18 h, une par personne et par jour au plus. */
class EnvoyerNotificationsCommande extends Command
{
    protected $signature = 'notifications:envoyer';

    protected $description = 'Envoie la notification du soir qui regroupe les nouveautés de chaque personne';

    public function handle(EnvoyerNotificationsDuSoir $envoyer): int
    {
        $r = $envoyer->handle();
        $this->info("{$r['personnes']} personne(s) prévenue(s), {$r['notifications']} notification(s) tentée(s).");

        return self::SUCCESS;
    }
}
