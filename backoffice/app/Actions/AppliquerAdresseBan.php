<?php

namespace App\Actions;

use App\Enums\PrecisionPosition;
use App\Models\Lieu;
use App\Models\Ville;
use App\Support\CodeInsee;
use App\Support\Point;

/**
 * Applique à un lieu le résultat de la Base Adresse Nationale choisi par l'admin (K04b) : adresse, code postal,
 * commune, position « calculée depuis l'adresse ». Fait par un admin, c'est une correction manuelle : les champs sont
 * verrouillés (la collecte ne les écrase plus) et les représentations du lieu suivent (position, commune).
 */
class AppliquerAdresseBan
{
    /** @param  array{adresse: string, code_postal: ?string, code_insee: ?string, position: Point}  $resultat */
    public function handle(Lieu $lieu, array $resultat): Lieu
    {
        $ville = $resultat['code_insee'] ? Ville::firstWhere('code_insee', CodeInsee::commune($resultat['code_insee'])) : null;

        $lieu->update(array_filter([
            'adresse' => $resultat['adresse'] ?: null,
            'code_postal' => $resultat['code_postal'],
            'ville_id' => $ville?->id,
            'position' => $resultat['position'],
            'precision_position' => PrecisionPosition::Adresse,
            'fuseau_horaire' => $ville?->fuseau_horaire,
        ], fn ($v) => $v !== null));

        return $lieu->fresh();
    }
}
