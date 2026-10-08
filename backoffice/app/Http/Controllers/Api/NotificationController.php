<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationEnvoyee;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** F3.7 : l'app signale qu'une notification a été ouverte (identifiant reçu dans ses données). */
class NotificationController extends Controller
{
    public function ouverte(Request $request, int $id): Response
    {
        NotificationEnvoyee::whereKey($id)->whereNull('ouverte_le')
            ->whereHas('appareil', fn ($q) => $q->where('identifiant', $request->attributes->get('appareil')))
            ->update(['ouverte_le' => now()]);

        return response()->noContent();
    }
}
