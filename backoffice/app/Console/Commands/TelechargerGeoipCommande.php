<?php

namespace App\Console\Commands;

use App\Web\VilleDuVisiteur;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Base de géolocalisation par adresse IP « DB-IP Lite » (licence CC BY 4.0, mise à jour chaque mois), gardée sur notre serveur :
 * la page d'accueil estime la ville du visiteur sans envoyer son adresse à un service tiers (W05a).
 */
class TelechargerGeoipCommande extends Command
{
    protected $signature = 'geoip:telecharger';

    protected $description = 'Télécharge la base DB-IP Lite (ville par adresse IP) du mois';

    public function handle(): int
    {
        $chemin = VilleDuVisiteur::cheminBase();
        @mkdir(dirname($chemin), 0755, true);

        foreach ([now(), now()->subMonth()] as $mois) {
            $url = 'https://download.db-ip.com/free/dbip-city-lite-'.$mois->format('Y-m').'.mmdb.gz';
            $reponse = Http::timeout(300)->withOptions(['sink' => $chemin.'.gz'])->get($url);

            if ($reponse->successful()) {
                file_put_contents($chemin.'.tmp', gzdecode((string) file_get_contents($chemin.'.gz')));
                rename($chemin.'.tmp', $chemin);
                @unlink($chemin.'.gz');
                $this->info('Base DB-IP '.$mois->format('m/Y').' installée ('.round(filesize($chemin) / 1048576).' Mo).');

                return self::SUCCESS;
            }
        }

        @unlink($chemin.'.gz');
        $this->error('Base DB-IP introuvable : la page d’accueil utilisera la ville par défaut.');

        return self::FAILURE;
    }
}
