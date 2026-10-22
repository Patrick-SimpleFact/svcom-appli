<?php

namespace App\Ia\Taches;

use App\Enums\FileATraiter;
use App\Models\PropositionIa;

/** File « Fusions à contrôler » : la collecte a fusionné deux séances dont les heures différaient un peu. */
class ControlerFusions extends ComparerSeances
{
    public function file(): FileATraiter
    {
        return FileATraiter::FusionAControler;
    }

    public function cleMode(): string
    {
        return 'ia_mode_fusions';
    }

    protected function choix(): array
    {
        return ['confirmer', 'separer'];
    }

    protected function tache(): string
    {
        return <<<'TXT'
            TÂCHE : deux annonces de sources différentes décrivent-elles LA MÊME séance (même spectacle, même lieu, même jour, même heure à quelques minutes près) ?
            « même séance » fusionne les deux annonces dans l'application (une seule séance, avec les deux billetteries) ; « séances différentes » les garde séparées.
            Une fusion à tort fait disparaître une vraie séance : en cas de doute, réponds « séances différentes » ou une confiance basse.

            MÊME SÉANCE si : même lieu, même date, heures égales ou proches (moins d'une heure d'écart), et titres qui désignent le même spectacle (catégorie en tête « Théâtre : », « Concert - », nom d'artiste ajouté, ville ou « Tournée » en plus, accents, majuscules, lettres masquées « c*ns »).
            SÉANCES DIFFÉRENTES si :
            - deux spectacles différents dans la même salle le même soir (plateau d'humour et spectacle, deux concerts, première partie vs tête d'affiche quand les deux ont leur propre billet) ;
            - deux horaires d'une même billetterie (une billetterie ne vend jamais deux fois la même séance à deux heures différentes) ;
            - éditions, saisons ou versions différentes (« Troisième édition » / « Quatrième édition », « version enfants ») ;
            - séances enchaînées : certains lieux jouent le même spectacle plusieurs fois par jour à quelques minutes d'intervalle (spectacles immersifs comme « Le Grand Hôtel des Rêves », comedy clubs à plusieurs plateaux). Deux heures différentes, même proches (11 h 00 et 11 h 20), y sont deux séances distinctes.
            Le moteur vérifie aussi deux choses dans les données, qui priment sur ta réponse : si une billetterie vend elle-même une séance du même spectacle à l'heure de l'autre annonce → séances différentes ; si une billetterie vend ce spectacle plusieurs fois en moins d'une heure le même jour → la paire est laissée à Patrick.

            Format d'un résultat :
            {"id": 12, "decision": "meme_seance", "confiance": 95, "justification": "…"}
            {"id": 13, "decision": "seances_differentes", "confiance": 90, "justification": "…"}

            TÂCHE : la collecte a déjà fusionné deux annonces dont les heures différaient de 1 à 30 minutes. Confirmer si c'est bien la même séance, ou demander de les séparer.
            Mêmes critères que les doublons probables. « séparer » rétablit deux séances distinctes.

            Format d'un résultat :
            {"id": 12, "decision": "confirmer", "confiance": 95, "justification": "…"}
            {"id": 13, "decision": "separer", "confiance": 90, "justification": "…"}
            TXT;
    }

    public function libelle(PropositionIa $proposition): string
    {
        return $this->fusionner($proposition->proposition) ? 'Confirmer la fusion' : 'Séparer les deux séances';
    }
}
