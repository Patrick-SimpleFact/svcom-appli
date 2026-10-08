<?php

namespace App\Http\Controllers\Api;

use App\Contributions\Contributions;
use App\Enums\MotifSignalement;
use App\Enums\TypePiste;
use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contributions (API §10, F5.6, F8) : signalements et pistes, avec ou sans compte ; « Mes propositions » avec compte.
 */
class ContributionController extends Controller
{
    public function signaler(Request $request, Contributions $contributions): JsonResponse
    {
        $d = $request->validate([
            'representation_id' => ['required', 'integer', 'exists:representations,id'],
            'motif' => ['required', Rule::enum(MotifSignalement::class)],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);
        $signalement = $contributions->signaler($request->attributes->get('appareil'), $this->utilisateur(), $d);

        return $this->json(['id' => $signalement->id, 'statut' => $signalement->statut->value], $signalement->wasRecentlyCreated ? 201 : 200);
    }

    public function proposer(Request $request, Contributions $contributions): JsonResponse
    {
        $d = $request->validate([
            'type' => ['required', Rule::enum(TypePiste::class)],
            'ville_id' => ['nullable', Rule::requiredIf($request->input('type') !== TypePiste::BilletterieOuAgenda->value), 'integer', 'exists:villes,id'],
            'nom' => ['required', 'string', 'min:2', 'max:200'],
            'lien' => ['nullable', 'url:http,https', 'max:500'],
            'commentaire' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'travaille_pour_le_lieu' => ['sometimes', 'boolean'],
        ]);
        $piste = $contributions->proposer($request->attributes->get('appareil'), $this->utilisateur(), $d);
        $lieu = $piste->lieu()->with('ville:id,nom')->first();

        return $this->json([
            'id' => $piste->id,
            'statut' => $piste->statut->value,
            'deja_connu' => $lieu ? ['id' => $lieu->id, 'nom' => $lieu->nom, 'ville' => $lieu->ville?->nom] : null,
            'reponse_possible' => filled($piste->email) || $piste->utilisateur_id !== null,
        ], 201);
    }

    public function lieuConnu(Request $request, Contributions $contributions): JsonResponse
    {
        $d = $request->validate(['nom' => ['required', 'string', 'max:200'], 'ville_id' => ['required', 'integer']]);
        $lieu = $contributions->lieuConnu($d['nom'], (int) $d['ville_id']);

        return $this->json(['lieu' => $lieu ? ['id' => $lieu->id, 'nom' => $lieu->nom, 'ville' => $lieu->ville?->nom] : null]);
    }

    public function propositions(Request $request, Contributions $contributions): JsonResponse
    {
        return $this->json(['propositions' => $contributions->propositions($request->user())]);
    }

    /** Compte facultatif (🔓) : rattaché s'il y a un jeton valide. */
    private function utilisateur(): ?Utilisateur
    {
        $u = auth('sanctum')->user();

        return $u instanceof Utilisateur ? $u : null;
    }

    private function json(array $donnees, int $statut = 200): JsonResponse
    {
        return response()->json($donnees, $statut, options: JSON_UNESCAPED_UNICODE);
    }
}
