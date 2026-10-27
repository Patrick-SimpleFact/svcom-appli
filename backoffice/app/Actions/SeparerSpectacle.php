<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\Admin;
use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\Spectacle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les dates de certains lieux ne sont pas ce spectacle (même titre, autre troupe) : elles passent dans un nouveau
 * spectacle, et leurs représentations suivent aussitôt (K07b). Les nouvelles dates de ces lieux rejoindront le nouveau
 * spectacle (rattachement « même lieu »), jamais l'ancien. Avec « repartir », un même titre se sépare en plusieurs spectacles.
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
            $nouveau = $this->detacher($spectacle, $aDetacher);
            $this->traiter($spectacle, ['separe' => true, 'nouveau_spectacle_id' => $nouveau->id]);

            return $nouveau;
        });
    }

    /**
     * Plusieurs spectacles sous un même titre : chaque lieu reçoit un numéro de spectacle. Le plus petit numéro garde
     * le spectacle actuel, chaque autre numéro devient un nouveau spectacle avec les dates de ses lieux.
     *
     * @param  array<int, int>  $groupes  lieu_id => numéro (un lieu absent garde le spectacle actuel)
     * @return list<Spectacle> les nouveaux spectacles
     */
    public function repartir(Spectacle $spectacle, array $groupes): array
    {
        $lieux = Offre::where('spectacle_id', $spectacle->id)->distinct()->pluck('lieu_id')->map(fn ($id) => (int) $id);
        $numeros = $lieux->mapWithKeys(fn (int $lieu) => [$lieu => (int) ($groupes[$lieu] ?? 1)]);
        $parNumero = $numeros->groupBy(fn (int $numero) => $numero, preserveKeys: true)->sortKeys();

        if ($parNumero->count() < 2) {
            throw new InvalidArgumentException('Donner un autre numéro aux lieux qui jouent un autre spectacle.');
        }

        return DB::transaction(function () use ($spectacle, $parNumero) {
            $nouveaux = $parNumero->slice(1)->map(fn ($lieux) => $this->detacher($spectacle,
                Offre::where('spectacle_id', $spectacle->id)->whereIn('lieu_id', $lieux->keys())->get(['id', 'meme_seance_que_id'])))->values()->all();
            $ids = array_map(fn (Spectacle $s) => $s->id, $nouveaux);
            $this->traiter($spectacle, ['separe' => true, 'nouveau_spectacle_id' => $ids[0], 'nouveaux_spectacles' => $ids]);

            return $nouveaux;
        });
    }

    /** Les offres passent dans un nouveau spectacle (copie du spectacle, sans ses corrections), et sont republiées. */
    private function detacher(Spectacle $spectacle, Collection $offres): Spectacle
    {
        $nouveau = $spectacle->replicate(['champs_verrouilles']);
        $nouveau->champs_verrouilles = [];
        $nouveau->saveQuietly();

        foreach ($offres->chunk(5000) as $paquet) {
            Offre::whereKey($paquet->modelKeys())->update(['spectacle_id' => $nouveau->id]);
        }

        $this->publier->publierGroupes($offres);

        return $nouveau;
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
