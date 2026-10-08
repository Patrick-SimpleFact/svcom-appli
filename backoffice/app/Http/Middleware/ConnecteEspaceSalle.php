<?php

namespace App\Http\Middleware;

use App\Http\Controllers\EspaceSalleController;
use App\Models\Utilisateur;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages de l'espace salle : un compte connecté qui gère encore au moins un lieu (accès retirable à tout moment, F9.2). */
class ConnecteEspaceSalle
{
    public function handle(Request $request, Closure $suite): Response
    {
        $utilisateur = Utilisateur::find($request->session()->get(EspaceSalleController::SESSION));

        if ($utilisateur === null || ! EspaceSalleController::estGestionnaire($utilisateur)) {
            $request->session()->forget(EspaceSalleController::SESSION);

            return redirect()->route('espace-salle.connexion');
        }

        $request->attributes->set('utilisateur_salle', $utilisateur);

        return $suite($request);
    }
}
