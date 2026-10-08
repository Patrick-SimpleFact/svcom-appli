<?php

namespace App\Console\Commands;

use App\Models\Lieu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Filet de sécurité (correctif du 08/10/2026) : un lieu nommé par son adresse (source sans nom, ex. export DATAtourisme)
 * prend le vrai nom qu'une source a fourni depuis (le plus récent). Jamais un nom corrigé à la main. Sans effet si tout va bien.
 */
class ReparerNomsLieuxCommande extends Command
{
    protected $signature = 'lieux:reparer-noms {--simulation : compter sans rien modifier}';

    protected $description = 'Remplace le nom des lieux nommés par leur adresse quand une source donne leur vrai nom';

    public function handle(): int
    {
        $candidats = Lieu::whereColumn('nom', 'adresse')->whereNull('fusionne_dans_id')->get()->filter->nomFabrique();
        $noms = DB::table('lieux_sources')->whereIn('lieu_id', $candidats->modelKeys())->whereRaw("coalesce(trim(nom), '') <> ''")
            ->orderByDesc('updated_at')->orderByDesc('id')->get(['lieu_id', 'nom'])->unique('lieu_id')->pluck('nom', 'lieu_id');

        $repares = 0;
        foreach ($candidats as $lieu) {
            if (($nom = $noms[$lieu->id] ?? null) !== null && $nom !== $lieu->nom) {
                $this->option('simulation') || $lieu->update(['nom' => mb_substr($nom, 0, 255)]);
                $repares++;
            }
        }

        $this->info(($this->option('simulation') ? 'Simulation : ' : '')."{$repares} lieu(x) renommé(s) sur {$candidats->count()} nommé(s) par leur adresse.");

        return self::SUCCESS;
    }
}
