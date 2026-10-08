<?php

use App\Http\Controllers\AccueilController;
use App\Http\Controllers\EspaceSalleController;
use App\Http\Controllers\PageLegaleController;
use App\Http\Controllers\PartageController;
use App\Http\Middleware\ConnecteEspaceSalle;
use Illuminate\Support\Facades\Route;

// Page d'accueil du site (W05a).
Route::get('/', [AccueilController::class, 'accueil'])->name('accueil');
Route::post('accueil/autour', [AccueilController::class, 'apercuPosition'])->name('accueil.autour')->middleware('throttle:30,1');

// Lien partagé (F5.8, API §12) et fichiers qui permettent au téléphone d'ouvrir l'app à la place de la page.
Route::get('s/{lien}', [PartageController::class, 'fiche'])->where('lien', '[a-z0-9-]+')->middleware('throttle:sortie');
Route::get('.well-known/apple-app-site-association', [PartageController::class, 'apple']);
Route::get('.well-known/assetlinks.json', [PartageController::class, 'android']);

// Espace salle (F9.1) : demande publique, connexion par lien d'invitation ou code e-mail, accueil des théâtres.
Route::prefix('espace-salle')->name('espace-salle.')->group(function () {
    Route::get('demande', [EspaceSalleController::class, 'demande'])->name('demande');
    Route::post('demande', [EspaceSalleController::class, 'deposer'])->name('deposer')->middleware('throttle:5,60');
    Route::get('merci', [EspaceSalleController::class, 'merci'])->name('merci');
    Route::get('connexion', [EspaceSalleController::class, 'connexion'])->name('connexion');
    Route::post('connexion', [EspaceSalleController::class, 'envoyerCode'])->name('code')->middleware('throttle:10,60');
    Route::post('connexion/code', [EspaceSalleController::class, 'verifierCode'])->name('verifier')->middleware('throttle:20,60');
    Route::get('invitation/{utilisateur}', [EspaceSalleController::class, 'invitation'])->name('invitation')->middleware('signed')->whereNumber('utilisateur');

    Route::middleware(ConnecteEspaceSalle::class)->group(function () {
        Route::get('/', [EspaceSalleController::class, 'accueil'])->name('accueil');
        Route::post('deconnexion', [EspaceSalleController::class, 'deconnexion'])->name('deconnexion');
    });
});

// Pages légales (F1.6, API §12).
Route::get('{slug}', PageLegaleController::class)->whereIn('slug', ['confidentialite', 'conditions', 'mentions-legales'])->name('page-legale');
