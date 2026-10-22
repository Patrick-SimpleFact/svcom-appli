<?php

namespace App\Console\Commands;

use App\Enums\FileATraiter;
use App\Ia\Assistant;
use Illuminate\Console\Command;

/**
 * Passage de l'assistant IA sur la boîte de travail (plan-module-ia) : planifié toutes les 15 minutes.
 * Ne fait rien tant que toutes les files sont « Désactivé » (aucun appel, pas même au solde du forfait).
 */
class TraiterIaCommande extends Command
{
    public const LOTS_PAR_PASSAGE = 6;

    protected $signature = 'ia:traiter {--file= : une seule file (ex. a_classer)} {--lots= : nombre maximal de demandes par file}';

    protected $description = 'Fait proposer (ou appliquer, en mode Actif) des décisions à l’assistant IA dans les files actives';

    public function handle(Assistant $assistant): int
    {
        $file = $this->option('file') ? FileATraiter::tryFrom((string) $this->option('file')) : null;

        if ($this->option('file') && $file === null) {
            $this->error('File inconnue. Valeurs possibles : '.implode(', ', array_keys(Assistant::TACHES)).'.');

            return self::FAILURE;
        }

        foreach ($assistant->traiter((int) ($this->option('lots') ?: self::LOTS_PAR_PASSAGE), $file) as $ligne) {
            $this->line($ligne);
        }

        return self::SUCCESS;
    }
}
