<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\Admin;
use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\Spectacle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les dates de certains lieux ne sont pas ce spectacle (même titre, autre troupe) : elles passent dans un nouveau
 * spectacle, et leurs représentations suivent aussitôt (K07b). Les nouvelles dates de ces lieux rejoindront le nouveau
 * spectacle (rattachement « même lieu »), jamais l'ancien.
 */
class SeparerSpectacle
{
    public function __construct(private PublierSource $publier) {}

    /** @param  list<int>  $lieux  lieux dont les dates sont détachées */
    public function handle(Spectacle $spectacle, array $lieux): Spectacle
    {
        $aDetacher = Offre::where('spectacle_id', $spectacle->id)->whereIn('lieu_id', $lieux)->get(['id', 'meme_seance_que_id']);
        $restantes = Offre::where('spectacle_id', $spectacle->id)->whereNotIn('lieu_id', $lieux)->exists();

        if ($aDetacher->isEmpty() || ! $restantes) {
            throw new InvalidArgumentException('Choisir au moins un lieu, mais pas tous : il doit rester des dates au spectacle.');
        }

        return DB::transaction(function () use ($spectacle, $aDetacher) {
            $nouveau = $spectacle->replicate(['champs_verrouilles']);
            $nouveau->champs_verrouilles = [];
            $nouveau->saveQuietly();

            foreach ($aDetacher->chunk(5000) as $paquet) {
                Offre::whereKey($paquet->modelKeys())->update(['spectacle_id' => $nouveau->id]);
            }

            $this->publier->publierGroupes($aDetacher);
            $this->traiter($spectacle, ['separe' => true, 'nouveau_spectacle_id' => $nouveau->id]);

            return $nouveau;
        });
    }

    /** « Même spectacle » : le regroupement est confirmé. */
    public function confirmer(Spectacle $spectacle): void
    {
        $this->traiter($spectacle, ['confirme' => true]);
    }

    private function traiter(Spectacle $spectacle, array $decision): void
    {
        ElementATraiter::where('file', FileATraiter::SpectacleAControler)
            ->where('cible_type', $spectacle->getMorphClass())
            ->where('cible_id', $spectacle->id)
            ->where('statut', StatutElement::EnAttente)
            ->update([
                'statut' => StatutElement::Traite,
                'decision' => json_encode($decision),
                'traite_par' => auth()->user() instanceof Admin ? auth()->id() : null,
                'traite_le' => now(),
            ]);
    }
}
