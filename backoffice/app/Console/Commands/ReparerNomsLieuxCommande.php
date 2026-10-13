<?php

namespace App\Console\Commands;

use App\Actions\RattacherLieu;
use App\Collecte\NomDeLieu;
use App\Models\Lieu;
use App\Models\LieuSource;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Filet de sécurité (correctif du 08/10/2026) : un lieu nommé par son adresse (source sans nom, ex. export DATAtourisme)
 * prend le vrai nom qu'une source a fourni depuis (le plus récent). Jamais un nom corrigé à la main. Sans effet si tout va bien.
 *
 * L01f (13/10/2026) : chez DATAtourisme, le nom de la salle pouvait rester collé après l'adresse
 * (« 41 rue Jobin, Cabaret Aléatoire, Friche la Belle de Mai ») ; il en est retiré, et la façon dont la source écrit
 * ce lieu est réécrite comme le connecteur la lit désormais, pour que la collecte suivante le retrouve.
 */
class ReparerNomsLieuxCommande extends Command
{
    protected $signature = 'lieux:reparer-noms {--simulation : compter sans rien modifier} {--detail : lister les noms retirés de l\'adresse}';

    protected $description = 'Remplace le nom des lieux nommés par leur adresse quand une source donne leur vrai nom';

    public function handle(RattacherLieu $rattacher): int
    {
        $simulation = (bool) $this->option('simulation');
        $separes = $this->separerNomsDatatourisme($rattacher, $simulation);

        $candidats = Lieu::whereColumn('nom', 'adresse')->whereNull('fusionne_dans_id')->get()->filter->nomFabrique();
        $noms = DB::table('lieux_sources')->whereIn('lieu_id', $candidats->modelKeys())->whereRaw("coalesce(trim(nom), '') <> ''")
            ->orderByDesc('updated_at')->orderByDesc('id')->get(['lieu_id', 'nom'])->unique('lieu_id')->pluck('nom', 'lieu_id');

        $repares = 0;
        foreach ($candidats as $lieu) {
            if (($nom = $noms[$lieu->id] ?? null) !== null && $nom !== $lieu->nom) {
                $simulation || $lieu->update(['nom' => mb_substr($nom, 0, 255)]);
                $repares++;
            }
        }

        $prefixe = $simulation ? 'Simulation : ' : '';
        $this->info("{$prefixe}{$separes} nom(s) de salle retiré(s) de l'adresse (DATAtourisme).");
        $this->info("{$prefixe}{$repares} lieu(x) renommé(s) sur {$candidats->count()} nommé(s) par leur adresse.");

        return self::SUCCESS;
    }

    /** Lieux vus sans nom chez DATAtourisme dont l'adresse contient le nom de la salle. */
    private function separerNomsDatatourisme(RattacherLieu $rattacher, bool $simulation): int
    {
        $source = Source::firstWhere('code', 'datatourisme');

        if ($source === null) {
            return 0;
        }

        $separes = 0;
        foreach (LieuSource::where('source_id', $source->id)->whereNull('nom')->whereNotNull('adresse')->with('lieu')->lazyById() as $vu) {
            [$nom, $reste] = NomDeLieu::separer(explode(', ', $vu->adresse));

            if ($nom === null || $reste === []) {
                continue;
            }

            $adresse = implode(', ', $reste);
            [$codePostal, $ville] = preg_match('/^(\d{5})\s*(.*)$/', (string) $vu->ville, $m) ? [$m[1], $m[2]] : [null, $vu->ville];
            $cle = $rattacher->cleSource($nom, $adresse, $codePostal, $ville);

            // Déjà revu par la collecte sous sa nouvelle forme : rien à réécrire.
            if (LieuSource::where('source_id', $source->id)->where('cle', $cle)->exists()) {
                continue;
            }

            $separes++;
            if ($this->option('detail')) {
                $this->line("#{$vu->lieu_id} « {$vu->adresse} » → « {$nom} » ({$adresse})");
            }

            if ($simulation) {
                continue;
            }

            DB::transaction(function () use ($vu, $nom, $adresse, $cle) {
                $lieu = $vu->lieu;

                if ($lieu !== null && $lieu->adresse === $vu->adresse) {
                    $lieu->update(array_filter([
                        'nom' => $lieu->nomFabrique() ? mb_substr($nom, 0, 255) : null,
                        'adresse' => $lieu->estVerrouille('adresse') ? null : mb_substr($adresse, 0, 255),
                    ]));
                }

                $vu->update(['nom' => mb_substr($nom, 0, 255), 'adresse' => mb_substr($adresse, 0, 255), 'cle' => $cle]);
            });
        }

        return $separes;
    }
}
