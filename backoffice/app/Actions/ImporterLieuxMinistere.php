<?php

namespace App\Actions;

use App\Enums\PrecisionPosition;
use App\Enums\TypeLieu;
use App\Models\Lieu;
use App\Models\Ville;
use App\Support\CodeInsee;
use App\Support\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Importe les lieux de spectacle de la base Basilic du ministère de la Culture
 * (data.gouv.fr, Licence Ouverte). Un champ corrigé à la main n'est jamais écrasé (F7.8).
 */
class ImporterLieuxMinistere
{
    public const JEU_DE_DONNEES = 'https://www.data.gouv.fr/api/1/datasets/61777ddaa9101d073e5506cd/';

    /** Types de la base Basilic qui relèvent du spectacle vivant. */
    private const TYPES_RETENUS = [
        'Théâtre',
        'Scène',
        'Opéra',
        'Centre de création artistique',
        'Centre de création musicale',
        'Centre culturel',
    ];

    /** @return array{creees: int, mises_a_jour: int, ignorees: int} */
    public function handle(?string $contenuCsv = null): array
    {
        $lignes = $this->lire($contenuCsv ?? $this->telecharger());
        $villes = Ville::pluck('id', 'code_insee');
        $fuseaux = Ville::pluck('fuseau_horaire', 'id');
        $compteur = ['creees' => 0, 'mises_a_jour' => 0, 'ignorees' => 0];

        DB::transaction(function () use ($lignes, $villes, $fuseaux, &$compteur) {
            foreach ($lignes as $ligne) {
                if (! in_array($ligne['Type équipement ou lieu'] ?? '', self::TYPES_RETENUS, true)) {
                    continue;
                }

                $reference = trim($ligne['Identifiant_deps_a_partir_de_2022'] ?? '');

                if ($reference === '' || ! is_numeric($ligne['Latitude'] ?? null) || ! is_numeric($ligne['Longitude'] ?? null)) {
                    $compteur['ignorees']++;

                    continue;
                }

                $villeId = $villes[CodeInsee::commune($ligne['code_insee'] ?? null) ?? ''] ?? null;

                $valeurs = [
                    'nom' => trim($ligne['Nom']),
                    'type' => $this->type($ligne),
                    'label' => $this->vide($ligne['Label et appellation'] ?? null),
                    'adresse' => $this->vide($ligne['Adresse'] ?? null),
                    'code_postal' => $this->vide($ligne['Code Postal'] ?? null),
                    'ville_id' => $villeId,
                    'position' => new Point((float) $ligne['Latitude'], (float) $ligne['Longitude']),
                    'precision_position' => PrecisionPosition::Exacte,
                    'fuseau_horaire' => $fuseaux[$villeId] ?? 'Europe/Paris',
                    'jauge' => is_numeric($ligne['Jauge_du_theatre'] ?? null) ? (int) $ligne['Jauge_du_theatre'] : null,
                ];

                $lieu = Lieu::firstWhere('ref_ministere', $reference);

                // Import automatique : ni journal ni verrouillage (ce n'est pas une correction manuelle).
                Lieu::withoutEvents(function () use ($lieu, $reference, $valeurs, &$compteur) {
                    if ($lieu === null) {
                        Lieu::create([...$valeurs, 'ref_ministere' => $reference]);
                        $compteur['creees']++;

                        return;
                    }

                    $lieu->fill(collect($valeurs)->reject(fn ($v, string $champ) => $lieu->estVerrouille($champ))->all())->save();
                    $compteur['mises_a_jour']++;
                });
            }
        });

        return $compteur;
    }

    private function telecharger(): string
    {
        $ressources = Http::timeout(60)->get(self::JEU_DE_DONNEES)->json('resources', []);
        $url = collect($ressources)->first(fn ($r) => ($r['format'] ?? '') === 'csv')['url'] ?? null;

        if ($url === null) {
            throw new RuntimeException('Fichier CSV de la base Basilic introuvable sur data.gouv.fr.');
        }

        $reponse = Http::timeout(600)->retry(3, 5000)->get($url);

        if (! $reponse->successful()) {
            throw new RuntimeException('Téléchargement de la base Basilic impossible ('.$reponse->status().').');
        }

        return $reponse->body();
    }

    /** @return list<array<string, string>> */
    private function lire(string $csv): array
    {
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($flux);

        $entetes = fgetcsv($flux, separator: ';', escape: '');
        $lignes = [];

        while (($valeurs = fgetcsv($flux, separator: ';', escape: '')) !== false) {
            if (count($valeurs) === count($entetes)) {
                $lignes[] = array_combine($entetes, $valeurs);
            }
        }

        fclose($flux);

        return $lignes;
    }

    private function type(array $ligne): TypeLieu
    {
        $label = $ligne['Label et appellation'] ?? '';

        return match (true) {
            $label === 'Scène de musiques actuelles' || $label === 'Zénith' => TypeLieu::SalleConcert,
            $label === 'Pôle national du cirque' => TypeLieu::Cirque,
            default => match ($ligne['Type équipement ou lieu']) {
                'Théâtre' => TypeLieu::Theatre,
                'Scène' => TypeLieu::Scene,
                'Opéra' => TypeLieu::Opera,
                'Centre culturel' => TypeLieu::CentreCulturel,
                default => TypeLieu::CentreCreation,
            },
        };
    }

    private function vide(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }
}
