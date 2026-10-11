import AsyncStorage from '@react-native-async-storage/async-storage';
import { useCallback, useEffect, useState } from 'react';

import { appelApi } from '@/api/client';

/** `lat`/`lon` : centre de la commune (absents pour une ville choisie avant M06). */
export type Ville = { id: number; nom: string; sous_titre?: string | null; lat?: number; lon?: number };

const CLE = 'spettacoli.ville_choisie';

/** Une ville choisie à la main reste active jusqu'au retour à « Ma position », ou au lancement suivant après 12 h (F2.1). */
export const DUREE_VILLE_MS = 12 * 60 * 60 * 1000;

/** Relit la ville choisie ; `null` si aucune, illisible ou choisie il y a plus de 12 h. */
export function lireVille(json: string | null, maintenant: number): Ville | null {
  try {
    const brut = JSON.parse(json ?? 'null') as { ville?: Ville; choisie_le?: number } | null;
    if (!brut?.ville || typeof brut.choisie_le !== 'number' || maintenant - brut.choisie_le > DUREE_VILLE_MS) {
      return null;
    }

    return brut.ville;
  } catch {
    return null;
  }
}

/** Villes proposées pendant la saisie (`GET /v1/recherche/propositions`, API §4). */
export async function chercherVilles(texte: string): Promise<Ville[]> {
  const reponse = await appelApi<{ villes: Ville[] }>('/v1/recherche/propositions', { parametres: { q: texte } });

  return reponse.villes;
}

/** Écrans qui affichent la ville de recherche (l'accueil) : prévenus quand un autre écran la change (recherche, F4.2). */
const abonnes = new Set<(v: Ville | null) => void>();

/** Change la ville de recherche de l'accueil ; `null` = revenir à « Ma position ». */
export function definirVilleRecherche(choix: Ville | null) {
  (choix ? AsyncStorage.setItem(CLE, JSON.stringify({ ville: choix, choisie_le: Date.now() })) : AsyncStorage.removeItem(CLE)).catch(() => {});
  abonnes.forEach((f) => f(choix));
}

export function useVilleChoisie() {
  const [ville, setVille] = useState<Ville | null>(null);
  const [lue, setLue] = useState(false);

  useEffect(() => {
    AsyncStorage.getItem(CLE)
      .then((json) => setVille(lireVille(json, Date.now())))
      .catch(() => {})
      .finally(() => setLue(true));
    abonnes.add(setVille);

    return () => {
      abonnes.delete(setVille);
    };
  }, []);

  const choisir = useCallback((choix: Ville | null) => definirVilleRecherche(choix), []);

  return { ville, lue, choisir };
}
