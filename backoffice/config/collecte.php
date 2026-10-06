<?php

use App\Collecte\Connecteurs\ConnecteurBilletReduc;
use App\Collecte\Connecteurs\ConnecteurDatatourisme;
use App\Collecte\Connecteurs\ConnecteurFactice;
use App\Collecte\Connecteurs\ConnecteurFnac;
use App\Collecte\Connecteurs\ConnecteurOpenagenda;
use App\Collecte\Connecteurs\ConnecteurParisQfap;
use App\Collecte\Connecteurs\ConnecteurTicketmaster;

return [
    /*
     * Un connecteur par source (code de la table `sources` → classe).
     * Ajouter une source = écrire son connecteur et l'ajouter ici (F7.3).
     */
    'connecteurs' => [
        'factice' => ConnecteurFactice::class,
        'factice_bis' => ConnecteurFactice::class,
        'billetreduc' => ConnecteurBilletReduc::class,
        'fnac' => ConnecteurFnac::class,
        'datatourisme' => ConnecteurDatatourisme::class,
        'openagenda' => ConnecteurOpenagenda::class,
        'ticketmaster' => ConnecteurTicketmaster::class,
        'paris_qfap' => ConnecteurParisQfap::class,
    ],

    // Disque des fichiers bruts et durée de conservation (jours).
    'disque_bruts' => env('COLLECTE_DISQUE', 'collecte'),
    'conservation_bruts_jours' => 30,

    // Une seule collecte à la fois : attente maximale de la fin de la précédente (au-delà, nouvel essai plus tard).
    'attente_max_secondes' => 7200,

    // Nouveaux essais après un échec : 15 min, 30 min, 1 h (F7.2).
    'delais_essais_secondes' => [900, 1800, 3600],

    // Sources où un score nul (aucun signal) vaut « exclu », pas « à trier » (décisions de Patrick du 06/10/2026) :
    // OpenAgenda (mots-clés libres, souvent absents), DATAtourisme (sans type de spectacle ni mot connu : visites, ventes…).
    'score_nul_exclu' => ['openagenda', 'datatourisme'],

    // Rattachement des lieux (COLLECTE §4, F7.5).
    'rapprochement_lieux_metres' => 200,
    'geocodage_url' => env('GEOCODAGE_URL', 'https://data.geopf.fr/geocodage/search'), // Base Adresse Nationale
    'geocodage_score_minimal' => 0.5,
];
