<?php

use App\Http\Controllers\Api\AppareilController;
use App\Http\Controllers\Api\AutourController;
use App\Http\Controllers\Api\CompteController;
use App\Http\Controllers\Api\ContributionController;
use App\Http\Controllers\Api\FicheController;
use App\Http\Controllers\Api\GoutsController;
use App\Http\Controllers\Api\RechercheController;
use App\Http\Controllers\Api\SuggestionController;
use App\Http\Controllers\SortieController;
use App\Http\Middleware\IdentifierAppareil;
use Illuminate\Support\Facades\Route;

/*
 * API de l'app (docs/API.md). Préfixe /v1 : l'API évolue sans casser les anciennes versions de l'app (F7.14).
 * Chaque appel : en-têtes X-Appareil et X-App-Version (API §1), limites par appareil et par adresse IP.
 */
Route::prefix('v1')->middleware([IdentifierAppareil::class, 'throttle:api'])->group(function () {
    // §2 Démarrage de l'app
    Route::post('appareils', [AppareilController::class, 'enregistrer']);
    Route::put('appareils/suggestion', [SuggestionController::class, 'choix']);

    // §3 Autour de moi
    Route::get('representations/autour', [AutourController::class, 'representations']);
    Route::get('lieux/carte', [AutourController::class, 'carte']);

    // §4 Recherche
    Route::get('recherche/propositions', [RechercheController::class, 'propositions']);
    Route::get('recherche', [RechercheController::class, 'rechercher']);

    // §5 Fiches
    Route::get('spectacles/{id}', [FicheController::class, 'spectacle'])->whereNumber('id');
    Route::get('spectacles/{id}/representations', [FicheController::class, 'autresDates'])->whereNumber('id');
    Route::get('lieux/{id}', [FicheController::class, 'lieu'])->whereNumber('id');
    Route::get('artistes/{id}', [FicheController::class, 'artiste'])->whereNumber('id');

    // §7 Compte
    Route::post('auth/code', [CompteController::class, 'demanderCode']);
    Route::post('auth/code/verification', [CompteController::class, 'verifierCode']);
    Route::post('auth/apple', [CompteController::class, 'apple']);
    Route::post('auth/google', [CompteController::class, 'google']);

    // §9 Suggestion à l'ouverture
    Route::get('suggestion', [SuggestionController::class, 'suggestion']);
    Route::post('suggestion/{affichage}', [SuggestionController::class, 'action'])->whereNumber('affichage');

    // §10 Contributions
    Route::post('signalements', [ContributionController::class, 'signaler']);
    Route::post('pistes', [ContributionController::class, 'proposer']);
    Route::get('pistes/lieu-connu', [ContributionController::class, 'lieuConnu']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/deconnexion', [CompteController::class, 'deconnexion']);
        Route::get('moi', [CompteController::class, 'moi']);
        Route::patch('moi', [CompteController::class, 'modifier']);
        Route::post('moi/export', [CompteController::class, 'exporter']);
        Route::delete('moi', [CompteController::class, 'supprimer']);

        // §8 Préférences, favoris, suivis, nouveautés
        Route::get('moi/preferences', [GoutsController::class, 'preferences']);
        Route::put('moi/preferences', [GoutsController::class, 'modifierPreferences']);
        Route::get('moi/favoris', [GoutsController::class, 'favoris']);
        Route::post('moi/favoris', [GoutsController::class, 'ajouterFavori']);
        Route::delete('moi/favoris/{id}', [GoutsController::class, 'retirerFavori'])->whereNumber('id');
        Route::get('moi/suivis', [GoutsController::class, 'suivis']);
        Route::post('moi/suivis', [GoutsController::class, 'suivre']);
        Route::delete('moi/suivis/{id}', [GoutsController::class, 'nePlusSuivre'])->whereNumber('id');
        Route::get('moi/nouveautes', [GoutsController::class, 'nouveautes']);
        Route::post('moi/nouveautes/vues', [GoutsController::class, 'marquerVues']);

        // §10 « Mes propositions »
        Route::get('moi/propositions', [ContributionController::class, 'propositions']);
    });
});

// §6 Sortie vers la billetterie : hors /v1 (ouverte par le navigateur intégré de l'app et la page web partagée, sans en-têtes),
// sans session ni cookie ; limite par adresse IP.
Route::get('sortie/{offre}', SortieController::class)->whereNumber('offre')->middleware('throttle:sortie');
