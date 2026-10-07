<?php

namespace App\Http\Controllers\Api;

use App\Api\AutourDeMoi;
use App\Api\Fiches;
use App\Http\Controllers\Controller;
use App\Support\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fiches (API §5) : spectacle, autres dates, lieu, artiste. Position facultative (distance, lieu le plus proche), jamais enregistrée.
 */
class FicheController extends Controller
{
    public function spectacle(Request $request, Fiches $fiches, int $id): JsonResponse
    {
        $d = $request->validate(['representation' => ['nullable', 'integer'], ...self::REGLES_POSITION]);

        return $this->json($fiches->spectacle($id, isset($d['representation']) ? (int) $d['representation'] : null, self::position($d), $request->attributes->get('appareil')));
    }

    public function autresDates(Request $request, Fiches $fiches, int $id): JsonResponse
    {
        return $this->json($fiches->autresDates($id, self::position($request->validate(self::REGLES_POSITION))));
    }

    public function lieu(Request $request, Fiches $fiches, int $id): JsonResponse
    {
        $d = $request->validate([...self::REGLES_POSITION, 'suivant' => ['nullable', 'string', 'max:200']]);

        return $this->json($fiches->lieu($id, self::position($d), AutourDeMoi::pageDuCurseur($d['suivant'] ?? null)));
    }

    public function artiste(Request $request, Fiches $fiches, int $id): JsonResponse
    {
        $d = $request->validate([...self::REGLES_POSITION, 'suivant' => ['nullable', 'string', 'max:200']]);

        return $this->json($fiches->artiste($id, self::position($d), AutourDeMoi::pageDuCurseur($d['suivant'] ?? null)));
    }

    private const REGLES_POSITION = [
        'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lon'],
        'lon' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
    ];

    private static function position(array $d): ?Point
    {
        return isset($d['lat']) ? new Point((float) $d['lat'], (float) $d['lon']) : null;
    }

    private function json(array $donnees): JsonResponse
    {
        return response()->json($donnees, options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
