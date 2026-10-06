<?php

namespace App\Collecte;

/**
 * Lit un tableau JSON énorme objet par objet, sans le charger en entier (flux Ticketmaster : ≈ 500 Mo décompressé).
 * Repère le début et la fin de chaque objet du premier tableau rencontré en suivant les accolades hors des textes.
 */
class ObjetsJsonEnFlux
{
    /**
     * @param  resource  $flux  flux ouvert en lecture (fichier ou gzopen)
     * @return iterable<int, string> le texte JSON de chaque objet
     */
    public static function objets($flux): iterable
    {
        $dansTableau = false;
        $profondeur = 0;
        $dansTexte = false;
        $echappe = false;
        $objet = '';

        while (($ligne = fgets($flux)) !== false) {
            $longueur = strlen($ligne);
            $debutObjet = $profondeur > 0 ? 0 : null;

            for ($i = 0; $i < $longueur; $i++) {
                $c = $ligne[$i];

                if ($dansTexte) {
                    if ($echappe) {
                        $echappe = false;
                    } elseif ($c === '\\') {
                        $echappe = true;
                    } elseif ($c === '"') {
                        $dansTexte = false;
                    }

                    continue;
                }

                if ($c === '"') {
                    $dansTexte = true;
                } elseif ($c === '[' && ! $dansTableau) {
                    $dansTableau = true;
                } elseif ($c === '{' && $dansTableau) {
                    if ($profondeur === 0) {
                        $debutObjet = $i;
                    }
                    $profondeur++;
                } elseif ($c === '}' && $dansTableau && $profondeur > 0) {
                    $profondeur--;
                    if ($profondeur === 0) {
                        yield $objet.substr($ligne, $debutObjet, $i - $debutObjet + 1);
                        $objet = '';
                        $debutObjet = null;
                    }
                }
            }

            if ($profondeur > 0 && $debutObjet !== null) {
                $objet .= substr($ligne, $debutObjet);
            }
        }
    }
}
