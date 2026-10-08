<?php

use App\Http\Controllers\PartageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Lien partagé (F5.8, API §12) et fichiers qui permettent au téléphone d'ouvrir l'app à la place de la page.
Route::get('s/{lien}', [PartageController::class, 'fiche'])->where('lien', '[a-z0-9-]+')->middleware('throttle:sortie');
Route::get('.well-known/apple-app-site-association', [PartageController::class, 'apple']);
Route::get('.well-known/assetlinks.json', [PartageController::class, 'android']);
