<?php

namespace App\Console\Commands;

use App\Contributions\Contributions;
use Illuminate\Console\Command;

/**
 * Durées de conservation (F7.15) : l'e-mail laissé avec une piste est effacé 12 mois après son traitement (F8.6).
 */
class EffacerEmailsPistesCommande extends Command
{
    protected $signature = 'pistes:effacer-emails';

    protected $description = 'Efface l’e-mail des pistes traitées depuis plus de 12 mois';

    public function handle(Contributions $contributions): int
    {
        $this->info("{$contributions->effacerEmailsAnciens()} e-mail(s) effacé(s).");

        return self::SUCCESS;
    }
}
