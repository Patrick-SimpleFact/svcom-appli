<?php

namespace App\Console\Commands;

use App\Actions\AlimenterNouveautes;
use Illuminate\Console\Command;

/**
 * Rappels du jour J (F3.4) : ajoutés chaque matin à la file des nouveautés pour les séances choisies des favoris.
 */
class RappelsJourJCommande extends Command
{
    protected $signature = 'nouveautes:rappels';

    protected $description = 'Ajoute les rappels « Ce soir : … » des favoris dont la séance choisie est aujourd’hui';

    public function handle(AlimenterNouveautes $alimenter): int
    {
        $this->info("{$alimenter->rappelsDuJour()} rappel(s) ajouté(s).");

        return self::SUCCESS;
    }
}
