<?php

use App\Http\Controllers\AccueilController;
use App\Http\Controllers\BetaController;
use App\Http\Controllers\EspaceSalleController;
use App\Http\Controllers\PageLegaleController;
use App\Http\Controllers\PartageController;
use App\Http\Controllers\ReferencementController;
use App\Http\Controllers\TelechargerAppController;
use App\Http\Middleware\ConnecteEspaceSalle;
use App\Http\Middleware\VersBackOffice;
use App\Http\Middleware\VersSitePublic;
use Illuminate\Support\Facades\Route;

// Page d'accueil du site (W05a).
Route::get('/', [AccueilController::class, 'accueil'])->name('accueil')->middleware(VersSitePublic::class);
Route::post('accueil/autour', [AccueilController::class, 'apercuPosition'])->name('accueil.autour')->middleware('throttle:30,1');

// Adresse unique des affiches et QR codes : vers la boutique du téléphone (plan marketing de lancement).
Route::get('app', TelechargerAppController::class)->name('telecharger-app')->middleware(VersSitePublic::class);

// Moteurs de recherche (SEO 1) : robots.txt selon le domaine, plan du site sur le domaine principal.
Route::get('robots.txt', [ReferencementController::class, 'robots']);
Route::get('sitemap.xml', [ReferencementController::class, 'plan'])->middleware(VersSitePublic::class);

// Liste d'attente de la bêta (W05b) : double opt-in, désinscription en un bouton.
Route::post('beta', [BetaController::class, 'inscrire'])->name('beta.inscrire')->middleware('throttle:5,60');
Route::get('beta/{inscription}/confirmer', [BetaController::class, 'confirmer'])->name('beta.confirmer')->middleware('signed')->whereNumber('inscription');
Route::get('beta/{inscription}/desinscrire', [BetaController::class, 'desinscription'])->name('beta.desinscrire')->middleware('signed')->whereNumber('inscription');
Route::post('beta/{inscription}/desinscrire', [BetaController::class, 'desinscrire'])->middleware('signed')->whereNumber('inscription');

// Lien partagé (F5.8, API §12) et fichiers qui permettent au téléphone d'ouvrir l'app à la place de la page.
Route::get('s/{lien}', [PartageController::class, 'fiche'])->where('lien', '[a-z0-9-]+')->middleware('throttle:sortie');
Route::get('.well-known/apple-app-site-association', [PartageController::class, 'apple']);
Route::get('.well-known/assetlinks.json', [PartageController::class, 'android']);

// Espace salle (F9.1) : demande publique, connexion par lien d'invitation ou code e-mail, accueil des théâtres.
// Servi seulement sur le sous-domaine du back-office (VersBackOffice) : jamais de page de connexion sur le domaine principal.
Route::prefix('espace-salle')->name('espace-salle.')->middleware(VersBackOffice::class)->group(function () {
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

// Pages exigées par les boutiques : assistance (App Store) et suppression du compte (Google Play).
// Sans formulaire ni champ de saisie : une page de connexion sur le domaine principal a valu l'alerte Safe Browsing du 14/10/2026.
Route::view('assistance', 'web.assistance')->name('assistance');
Route::view('suppression-compte', 'web.suppression-compte')->name('suppression-compte');

// Pages légales (F1.6, API §12).
Route::get('{slug}', PageLegaleController::class)->whereIn('slug', ['confidentialite', 'conditions', 'mentions-legales'])->name('page-legale');
