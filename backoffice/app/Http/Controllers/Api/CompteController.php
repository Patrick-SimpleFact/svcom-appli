<?php

namespace App\Http\Controllers\Api;

use App\Comptes\CodesConnexion;
use App\Comptes\Comptes;
use App\Comptes\JetonsExternes;
use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compte (API §7, F1) : connexion par code e-mail, Apple ou Google ; profil, export, suppression.
 * Jeton de connexion : en-tête « Authorization: Bearer … » (Sanctum), sans expiration tant qu'on ne se déconnecte pas (F1.5).
 */
class CompteController extends Controller
{
    /** Ce que le mode invité a gardé sur le téléphone, repris à la 1re connexion (F1.4). */
    private const REGLES_INVITE = [
        'gouts' => ['nullable', 'array'], 'gouts.*' => ['integer'],
        'rayon_m' => ['nullable', 'integer', 'min:1000', 'max:50000'],
    ];

    public function demanderCode(Request $request, CodesConnexion $codes): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $codes->envoyer($d['email']);

        return $this->json(['envoye' => true, 'valable_minutes' => CodesConnexion::VALIDITE_MINUTES], 202);
    }

    public function verifierCode(Request $request, CodesConnexion $codes, Comptes $comptes): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], 'code' => ['required', 'digits:6'], ...self::REGLES_INVITE]);
        $email = $codes->verifier($d['email'], $d['code']);

        return $this->json($comptes->connecter($email, null, null, $request->attributes->get('appareil'), $d));
    }

    public function apple(Request $request, JetonsExternes $jetons, Comptes $comptes): JsonResponse
    {
        return $this->externe('apple', $request, $jetons, $comptes);
    }

    public function google(Request $request, JetonsExternes $jetons, Comptes $comptes): JsonResponse
    {
        return $this->externe('google', $request, $jetons, $comptes);
    }

    private function externe(string $fournisseur, Request $request, JetonsExternes $jetons, Comptes $comptes): JsonResponse
    {
        $d = $request->validate(['jeton' => ['required', 'string', 'max:5000'], ...self::REGLES_INVITE]);
        $identite = $jetons->verifier($fournisseur, $d['jeton']);

        return $this->json($comptes->connecter($identite['email'], $fournisseur, $identite['identifiant'], $request->attributes->get('appareil'), $d));
    }

    public function deconnexion(Request $request): JsonResponse
    {
        /** @var Utilisateur $u */
        $u = $request->user();
        $u->currentAccessToken()->delete();
        $u->appareils()->where('identifiant', $request->attributes->get('appareil'))->update(['utilisateur_id' => null]);

        return response()->json(null, 204);
    }

    public function moi(Request $request, Comptes $comptes): JsonResponse
    {
        return $this->json($comptes->moi($request->user()));
    }

    public function modifier(Request $request, Comptes $comptes): JsonResponse
    {
        $d = $request->validate([
            'prenom' => ['sometimes', 'required', 'string', 'max:50'],
            'naissance_mois' => ['sometimes', 'required', 'integer', 'between:1,12', 'required_with:naissance_annee'],
            'naissance_annee' => ['sometimes', 'required', 'integer', 'between:1900,'.now()->year, 'required_with:naissance_mois'],
            'cgu_acceptees' => ['sometimes', 'accepted'],
            'lettre_info' => ['sometimes', 'boolean'],
        ]);

        return $this->json($comptes->modifier($request->user(), $d));
    }

    public function exporter(Request $request, Comptes $comptes): JsonResponse
    {
        $comptes->exporter($request->user());

        return $this->json(['envoye' => true], 202);
    }

    public function supprimer(Request $request, Comptes $comptes): JsonResponse
    {
        $comptes->supprimer($request->user());

        return response()->json(null, 204);
    }

    private function json(array $donnees, int $statut = 200): JsonResponse
    {
        return response()->json($donnees, $statut, options: JSON_UNESCAPED_UNICODE);
    }
}
