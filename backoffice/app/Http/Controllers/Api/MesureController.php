<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mesure\Evenements;
use App\Support\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mesure (API §11) : les événements d'usage arrivent par lots (toutes les 30 s au plus et à la mise en arrière-plan). */
class MesureController extends Controller
{
    public function evenements(Request $request, Evenements $evenements): JsonResponse
    {
        $d = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lon'],
            'lon' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'evenements' => ['required', 'array', 'min:1', 'max:'.Evenements::MAX_PAR_LOT],
            'evenements.*.type' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'evenements.*.horodatage' => ['required', 'date'],
            'evenements.*.ville_id' => ['nullable', 'integer'],
            'evenements.*.donnees' => ['nullable', 'array'],
        ]);

        $resultat = $evenements->enregistrer(
            $request->attributes->get('appareil'),
            $d['evenements'],
            isset($d['lat']) ? new Point((float) $d['lat'], (float) $d['lon']) : null,
        );

        return response()->json($resultat, 202);
    }
}
