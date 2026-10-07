<?php

use App\Http\Controllers\Api\AppareilController;
use App\Http\Middleware\IdentifierAppareil;
use Illuminate\Support\Facades\Route;

/*
 * API de l'app (docs/API.md). Préfixe /v1 : l'API évolue sans casser les anciennes versions de l'app (F7.14).
 * Chaque appel : en-têtes X-Appareil et X-App-Version (API §1), limites par appareil et par adresse IP.
 */
Route::prefix('v1')->middleware([IdentifierAppareil::class, 'throttle:api'])->group(function () {
    // §2 Démarrage de l'app
    Route::post('appareils', [AppareilController::class, 'enregistrer']);
});
