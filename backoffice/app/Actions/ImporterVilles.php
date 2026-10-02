<?php

namespace App\Actions;

use App\Models\Ville;
use App\Support\FuseauHoraire;
use App\Support\Point;
use App\Support\Texte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Importe ou met à jour les communes françaises depuis l'API officielle geo.api.gouv.fr.
 * Ne touche jamais au choix « ville pilote » fait dans le BO.
 */
class ImporterVilles
{
    public const SOURCE = 'https://geo.api.gouv.fr/communes';

    /** @return array{creees: int, mises_a_jour: int} */
    public function handle(): array
    {
        $reponse = Http::timeout(180)->retry(3, 2000)->get(self::SOURCE, [
            'fields' => 'nom,code,codeDepartement,centre,population,codesPostaux',
            'format' => 'json',
        ]);

        if (! $reponse->successful() || ! is_array($reponse->json())) {
            throw new RuntimeException('Import des communes impossible (geo.api.gouv.fr a répondu '.$reponse->status().').');
        }

        $avant = Ville::count();
        $maintenant = now();
        $lignes = [];

        foreach ($reponse->json() as $commune) {
            if (empty($commune['centre']['coordinates']) || empty($commune['code'])) {
                continue;
            }

            [$longitude, $latitude] = $commune['centre']['coordinates'];

            $lignes[] = [
                'code_insee' => $commune['code'],
                'nom' => $commune['nom'],
                'nom_normalise' => Texte::normaliser($commune['nom']),
                'departement' => $commune['codeDepartement'] ?? '',
                'codes_postaux' => json_encode($commune['codesPostaux'] ?? []),
                'population' => $commune['population'] ?? null,
                'position' => (new Point($latitude, $longitude))->versEwkt(),
                'fuseau_horaire' => FuseauHoraire::pourDepartement($commune['codeDepartement'] ?? null),
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
        }

        // Import automatique par paquets : pas d'inscription au journal des actions manuelles,
        // et la colonne est_pilote (choix du BO) n'est jamais écrasée.
        DB::transaction(function () use ($lignes) {
            foreach (array_chunk($lignes, 1000) as $paquet) {
                DB::table('villes')->upsert(
                    $paquet,
                    ['code_insee'],
                    ['nom', 'nom_normalise', 'departement', 'codes_postaux', 'population', 'position', 'fuseau_horaire', 'updated_at'],
                );
            }
        });

        $total = Ville::count();
        $compteur = ['creees' => $total - $avant, 'mises_a_jour' => count($lignes) - ($total - $avant)];

        return $compteur;
    }
}
