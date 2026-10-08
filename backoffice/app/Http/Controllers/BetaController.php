<?php

namespace App\Http\Controllers;

use App\Models\InscriptionBeta;
use App\Web\ListeAttenteBeta;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Liste d'attente de la bêta (W05b) : formulaire de la page d'accueil, confirmation et désinscription par lien signé. */
class BetaController extends Controller
{
    public function inscrire(Request $request, ListeAttenteBeta $liste): RedirectResponse
    {
        $d = $request->validateWithBag('beta', [
            'email' => ['required', 'email', 'max:255'],
            'plateforme' => ['required', Rule::in(array_keys(InscriptionBeta::PLATEFORMES))],
            'ville' => ['nullable', 'string', 'max:100'],
            'consentement' => ['accepted'],
        ], ['consentement.accepted' => 'Cochez la case pour que nous puissions vous écrire.', 'plateforme.required' => 'Choisissez iPhone ou Android.']);

        // Anti-robot sans service tiers (champ piège, 3 s minimum) ; la réponse est la même dans tous les cas.
        if (blank($request->input('site_entreprise')) && ! $this->tropRapide($request->input('debut'))) {
            $liste->inscrire($d['email'], $d['plateforme'], $d['ville'] ?? null);
        }

        return redirect()->to(route('accueil').'#beta')->with('beta', 'Merci ! Un e-mail de confirmation vient de partir : cliquez sur le lien pour valider votre inscription.');
    }

    public function confirmer(InscriptionBeta $inscription, ListeAttenteBeta $liste): View
    {
        $liste->confirmer($inscription);

        return view('web.message', ['titre' => 'Inscription confirmée', 'texte' => 'Vous êtes sur la liste d’attente de la bêta de Spettacoli. Nous vous écrirons dès son ouverture.']);
    }

    /** Page de désinscription : un bouton à presser (un lien seul serait suivi par les antivirus des messageries). */
    public function desinscription(InscriptionBeta $inscription): View
    {
        return view('web.desinscription-beta', ['inscription' => $inscription]);
    }

    public function desinscrire(InscriptionBeta $inscription, ListeAttenteBeta $liste): View
    {
        $liste->desinscrire($inscription);

        return view('web.message', ['titre' => 'C’est fait', 'texte' => 'Votre adresse a été effacée de la liste d’attente.']);
    }

    private function tropRapide(?string $debut): bool
    {
        try {
            return now()->timestamp - (int) Crypt::decrypt((string) $debut) < EspaceSalleController::SECONDES_MIN;
        } catch (DecryptException) {
            return true;
        }
    }
}
