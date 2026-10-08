<?php

/*
 * L'app mobile vue depuis le web (W01, F5.8) : liens des boutiques, ouverture directe de l'app
 * par les liens partagés (liens universels Apple, App Links Android). À renseigner au bloc 7.
 */
return [
    'app_store' => env('APP_STORE_URL'),
    'google_play' => env('GOOGLE_PLAY_URL'),

    // Ouvre l'app quand elle est installée (spettacoli://spectacle/12).
    'schema' => env('APP_SCHEMA', 'spettacoli'),

    // iOS : identifiant d'équipe Apple + identifiant de l'app (apple-app-site-association).
    'ios_equipe_id' => env('APNS_TEAM_ID'),
    'ios_bundle_id' => env('APNS_BUNDLE_ID'),

    // Android : nom du paquet + empreinte SHA-256 du certificat de signature (assetlinks.json), séparées par des virgules.
    'android_paquet' => env('ANDROID_PACKAGE'),
    'android_empreintes' => env('ANDROID_SHA256_FINGERPRINTS'),
];
