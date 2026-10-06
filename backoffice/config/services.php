<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Awin (BilletRéduc, Fnac) : adresse secrète de la liste des flux du compte éditeur (COLLECTE §3).
    // OpenAgenda : clé d'API (COLLECTE §3).
    'openagenda' => [
        'cle' => env('OPENAGENDA_API_KEY'),
        'adresse' => 'https://api.openagenda.com/v2',
    ],

    // DATAtourisme : clé de l'API REST (api.datatourisme.fr, 1 000 requêtes / heure).
    'datatourisme' => [
        'cle' => env('DATATOURISME_API_KEY'),
        'adresse' => 'https://api.datatourisme.fr/v1',
    ],

    'awin' => [
        'liste_flux' => env('AWIN_FEEDLIST_URL'),
    ],

];
