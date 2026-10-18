<?php

namespace App\Actions;

use App\Models\Offre;
use App\Models\Representation;
use App\Models\Spectacle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deux fiches pour le même spectacle (« Humour “Les c*ns, c'est les autres” » et « Les cons c'est les autres ») :
 * tout ce qui pointe vers la première passe à la seconde, qui garde son titre, sa description et ses corrections.
 * Ses dates sont republiées aussitôt. La première, restée vide, est supprimée par l'entretien du catalogue (K08b).
 */
class FusionnerSpectacles
{
    public function __construct(private PublierSource $publier) {}

    public function handle(Spectacle $doublon, Spectacle $garde): void
    {
        if ($doublon->is($garde)) {
            throw new InvalidArgumentException('Un spectacle ne peut pas être fusionné avec lui-même.');
        }

        DB::transaction(function () use ($doublon, $garde) {
            $offres = Offre::where('spectacle_id', $doublon->id)->get(['id', 'meme_seance_que_id']);

            foreach ($offres->chunk(5000) as $paquet) {
                Offre::whereKey($paquet->modelKeys())->update(['spectacle_id' => $garde->id]);
            }

            $this->publier->publierGroupes($offres);
            Representation::where('spectacle_id', $doublon->id)->update(['spectacle_id' => $garde->id]); // dates sans offre

            // Un favori déjà posé sur le spectacle gardé l'emporte (un seul par personne).
            DB::table('favoris')->where('spectacle_id', $doublon->id)
                ->whereIn('utilisateur_id', DB::table('favoris')->where('spectacle_id', $garde->id)->select('utilisateur_id'))
                ->delete();

            foreach (['favoris', 'nouveautes', 'campagnes', 'affichages_suggestion', 'clics_sortants'] as $table) {
                DB::table($table)->where('spectacle_id', $doublon->id)->update(['spectacle_id' => $garde->id]);
            }

            DB::statement('insert into spectacle_artiste (spectacle_id, artiste_id, role)
                select ?, artiste_id, role from spectacle_artiste where spectacle_id = ? on conflict do nothing', [$garde->id, $doublon->id]);
            DB::table('spectacle_artiste')->where('spectacle_id', $doublon->id)->delete();
        });
    }
}
