<?php

use App\Collecte\Connecteurs\ConnecteurBilletReduc;
use App\Collecte\Connecteurs\ConnecteurDatatourisme;
use App\Collecte\Connecteurs\ConnecteurFactice;
use App\Collecte\Connecteurs\ConnecteurFnac;

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
    ],

    // Disque des fichiers bruts et durée de conservation (jours).
    'disque_bruts' => env('COLLECTE_DISQUE', 'collecte'),
    'conservation_bruts_jours' => 30,

    // Nouveaux essais après un échec : 15 min, 30 min, 1 h (F7.2).
    'delais_essais_secondes' => [900, 1800, 3600],

    // Rattachement des lieux (COLLECTE §4, F7.5).
    'rapprochement_lieux_metres' => 200,
    'geocodage_url' => env('GEOCODAGE_URL', 'https://data.geopf.fr/geocodage/search'), // Base Adresse Nationale
    'geocodage_score_minimal' => 0.5,
];
