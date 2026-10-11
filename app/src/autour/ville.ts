import AsyncStorage from '@react-native-async-storage/async-storage';
import { useCallback, useEffect, useState } from 'react';

import { appelApi } from '@/api/client';

export type Ville = { id: number; nom: string; sous_titre?: string | null };

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

export function useVilleChoisie() {
  const [ville, setVille] = useState<Ville | null>(null);
  const [lue, setLue] = useState(false);

  useEffect(() => {
    AsyncStorage.getItem(CLE)
      .then((json) => setVille(lireVille(json, Date.now())))
      .catch(() => {})
      .finally(() => setLue(true));
  }, []);

  /** `null` = revenir à « Ma position ». */
  const choisir = useCallback((choix: Ville | null) => {
    setVille(choix);
    (choix ? AsyncStorage.setItem(CLE, JSON.stringify({ ville: choix, choisie_le: Date.now() })) : AsyncStorage.removeItem(CLE)).catch(() => {});
  }, []);

  return { ville, lue, choisir };
}
