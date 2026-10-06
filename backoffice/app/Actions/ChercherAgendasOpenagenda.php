<?php

namespace App\Actions;

use App\Collecte\ApiOpenagenda;
use App\Enums\FrequenceAgenda;
use App\Enums\OrigineAgenda;
use App\Models\AgendaOpenagenda;
use App\Models\Ville;

/**
 * Remplit la liste des agendas OpenAgenda suivis pour une ville (COLLECTE §3 : pas de recherche nationale d'événements).
 * Les agendas déjà connus ne sont pas modifiés (un agenda désactivé à la main le reste).
 */
class ChercherAgendasOpenagenda
{
    public function __construct(private ApiOpenagenda $api) {}

    /** @return array{trouves: int, ajoutes: int} */
    public function handle(Ville $ville, int $maximum = 100): array
    {
        $agendas = $this->api->chercherAgendas($ville->nom, $maximum);
        $ajoutes = 0;

        foreach ($agendas as $agenda) {
            $nouveau = AgendaOpenagenda::firstOrCreate(['uid' => $agenda['uid']], [
                'nom' => $agenda['nom'],
                'slug' => $agenda['slug'],
                'ville_id' => $ville->id,
                'officiel' => $agenda['officiel'],
                'frequence' => FrequenceAgenda::Normale,
                'actif' => true,
                'origine' => OrigineAgenda::Recherche,
            ]);

            $ajoutes += $nouveau->wasRecentlyCreated ? 1 : 0;
        }

        return ['trouves' => count($agendas), 'ajoutes' => $ajoutes];
    }
}
