import AsyncStorage from '@react-native-async-storage/async-storage';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';

/** Rayons proposés (F2.4, API §3) en mètres ; `null` = automatique (élargi jusqu'à avoir assez de spectacles). */
export const RAYONS_M = [null, 1000, 2000, 5000, 10000, 25000, 50000] as const;
export type RayonM = (typeof RAYONS_M)[number];

/** Goûts du mode invité (F1.1, F1.10 point 3) : au format de l'API, pour être repris tels quels à la création du compte (API §7). */
export type Gouts = { genres: number[]; rayon_m: RayonM };

export const GOUTS_PAR_DEFAUT: Gouts = { genres: [], rayon_m: null };

const CLE_GOUTS = 'spettacoli.gouts';
const CLE_ACCUEIL = 'spettacoli.premier_lancement_fait';

type Etat = {
  /** `false` tant que le téléphone n'a pas été lu (au lancement, quelques millisecondes). */
  charge: boolean;
  gouts: Gouts;
  /** Bienvenue et goûts déjà vus (remplis ou passés) : on ne les remontre plus. */
  premierLancementFait: boolean;
  terminerPremierLancement: (gouts: Gouts | null) => void;
};

const Contexte = createContext<Etat>({ charge: false, gouts: GOUTS_PAR_DEFAUT, premierLancementFait: false, terminerPremierLancement: () => {} });

export const useGouts = () => useContext(Contexte);

/** Libellé d'un rayon : « Automatique », « 1 km »… */
export function libelleRayon(rayon: RayonM): string {
  return rayon === null ? 'Automatique' : `${rayon / 1000} km`;
}

/** Relit des goûts enregistrés, en écartant ce qui ne serait plus valable (format changé, rayon disparu). */
export function lireGouts(json: string | null): Gouts {
  try {
    const brut = JSON.parse(json ?? 'null') as Partial<Gouts> | null;
    const genres = Array.isArray(brut?.genres) ? brut.genres.filter((g): g is number => Number.isInteger(g)) : [];
    const rayon_m = RAYONS_M.includes(brut?.rayon_m as RayonM) ? (brut?.rayon_m as RayonM) : null;

    return { genres, rayon_m };
  } catch {
    return GOUTS_PAR_DEFAUT;
  }
}

/** Goûts et premier lancement, gardés sur le téléphone (F1.1). */
export function GoutsProvider({ children }: { children: ReactNode }) {
  const [charge, setCharge] = useState(false);
  const [gouts, setGouts] = useState<Gouts>(GOUTS_PAR_DEFAUT);
  const [premierLancementFait, setPremierLancementFait] = useState(false);

  useEffect(() => {
    AsyncStorage.multiGet([CLE_GOUTS, CLE_ACCUEIL])
      .then(([[, json], [, fait]]) => {
        setGouts(lireGouts(json));
        setPremierLancementFait(fait === '1');
      })
      .catch(() => {})
      .finally(() => setCharge(true));
  }, []);

  // `null` = « Passer » : rien n'est enregistré, l'accueil part des réglages par défaut.
  const terminerPremierLancement = useCallback((choix: Gouts | null) => {
    if (choix) {
      setGouts(choix);
      AsyncStorage.setItem(CLE_GOUTS, JSON.stringify(choix)).catch(() => {});
    }
    setPremierLancementFait(true);
    AsyncStorage.setItem(CLE_ACCUEIL, '1').catch(() => {});
  }, []);

  const valeur = useMemo(
    () => ({ charge, gouts, premierLancementFait, terminerPremierLancement }),
    [charge, gouts, premierLancementFait, terminerPremierLancement],
  );

  return <Contexte.Provider value={valeur}>{children}</Contexte.Provider>;
}
