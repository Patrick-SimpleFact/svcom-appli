<?php

namespace App\Actions;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\Offre;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deux fiches désignent le même lieu (ex. « Théâtre de l'Observance » et « Observance – salle 1 ») :
 * le doublon est rattaché au lieu conservé, qui récupère les informations qui lui manquaient (F7.5).
 * Appliqué tout de suite (F7.2) : annonces et représentations passent au lieu conservé (position et commune comprises),
 * et le doublon sort de la file « Lieux à vérifier ».
 */
class FusionnerLieux
{
    private const CHAMPS_COMPLETES = ['label', 'adresse', 'code_postal', 'ville_id', 'telephone', 'site_web', 'jauge', 'ref_ministere'];

    public function handle(Lieu $doublon, Lieu $conserve): Lieu
    {
        if ($doublon->is($conserve)) {
            throw new InvalidArgumentException('Un lieu ne peut pas être fusionné avec lui-même.');
        }

        if ($conserve->fusionne_dans_id !== null) {
            throw new InvalidArgumentException('Le lieu conservé a lui-même été fusionné : choisir le lieu final.');
        }

        return DB::transaction(function () use ($doublon, $conserve) {
            $complements = collect(self::CHAMPS_COMPLETES)
                ->filter(fn (string $champ) => blank($conserve->{$champ}) && filled($doublon->{$champ}))
                ->mapWithKeys(fn (string $champ) => [$champ => $doublon->{$champ}])
                ->all();

            // La référence du Ministère est unique : on la libère avant de la transférer.
            if (isset($complements['ref_ministere'])) {
                $doublon->update(['ref_ministere' => null]);
            }

            $doublon->update(['fusionne_dans_id' => $conserve->id, 'masque' => true]);

            if ($complements !== []) {
                $conserve->update($complements);
            }

            Offre::where('lieu_id', $doublon->id)->update(['lieu_id' => $conserve->id]);

            DB::update(<<<'SQL'
                update representations r set lieu_id = l.id, position = l.position, ville_id = l.ville_id
                from lieux l where l.id = ? and r.lieu_id = ?
                SQL, [$conserve->id, $doublon->id]);

            ElementATraiter::where('file', FileATraiter::LieuAVerifier)
                ->where('cible_type', $doublon->getMorphClass())->where('cible_id', $doublon->id)
                ->where('statut', StatutElement::EnAttente)
                ->update([
                    'statut' => StatutElement::Traite,
                    'decision' => json_encode(['fusionne_dans' => $conserve->id]),
                    'traite_par' => auth()->id(),
                    'traite_le' => now(),
                ]);

            // Ce qui désigne le lieu hors du catalogue suit aussi : accès des théâtres (F9), suivis (F3), demandes, annonceurs, pistes.
            // Un compte qui avait déjà le lieu conservé garde une seule ligne.
            foreach (['statuts_utilisateur' => ['utilisateur_id', 'statut'], 'suivis' => ['utilisateur_id', 'type']] as $table => [$personne, $nature]) {
                $cibles = $table === 'suivis' ? ['cible_id', "and s.type = 'lieu'"] : ['lieu_id', ''];
                DB::delete("delete from {$table} s where s.{$cibles[0]} = ? {$cibles[1]} and exists (select 1 from {$table} t where t.{$personne} = s.{$personne}
                    and t.{$nature} = s.{$nature} and t.{$cibles[0]} = ?)", [$doublon->id, $conserve->id]);
                DB::table($table)->where($cibles[0], $doublon->id)->when($table === 'suivis', fn ($q) => $q->where('type', 'lieu'))->update([$cibles[0] => $conserve->id]);
            }
            foreach (['demandes_espace_salle' => ['lieu_id', 'lieu_propose_id'], 'annonceurs' => ['lieu_id'], 'pistes' => ['lieu_id']] as $table => $colonnes) {
                foreach ($colonnes as $colonne) {
                    DB::table($table)->where($colonne, $doublon->id)->update([$colonne => $conserve->id]);
                }
            }

            // Les autres doublons déjà rattachés au doublon suivent vers le lieu conservé.
            Lieu::where('fusionne_dans_id', $doublon->id)->get()
                ->each(fn (Lieu $lieu) => $lieu->update(['fusionne_dans_id' => $conserve->id]));

            return $conserve->fresh();
        });
    }
}
