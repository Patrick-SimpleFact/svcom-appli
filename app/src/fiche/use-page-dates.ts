import { useEffect, useRef, useState } from 'react';
import type { NativeScrollEvent, NativeSyntheticEvent } from 'react-native';

import { ajouterJours, type CarteRecherche } from '@/fiche/pages';

type AvecDates = { jours: { date: string; cartes: CarteRecherche[] }[]; suivant: string | null };

/** Page lieu ou artiste : premier chargement, puis la suite des dates au défilement. */
export function usePageDates<T extends AvecDates>(charger: (suivant?: string | null) => Promise<T>, cle: string) {
  const [page, setPage] = useState<T | null>(null);
  const [erreur, setErreur] = useState(false);
  const [essai, setEssai] = useState(0);
  const enCours = useRef(false);
  const chargerRef = useRef(charger);

  useEffect(() => {
    chargerRef.current = charger;
  }, [charger]);

  useEffect(() => {
    let actif = true;
    chargerRef.current()
      .then((p) => actif && setPage(p))
      .catch(() => actif && setErreur(true));

    return () => {
      actif = false;
    };
  }, [cle, essai]);

  const suite = async () => {
    if (!page?.suivant || enCours.current) {
      return;
    }
    enCours.current = true;
    try {
      const plus = await chargerRef.current(page.suivant);
      setPage({ ...page, jours: ajouterJours(page.jours, plus.jours), suivant: plus.suivant });
    } catch {
      // Nouvel essai au prochain défilement.
    } finally {
      enCours.current = false;
    }
  };

  /** À brancher sur `onScroll` : charge la suite à l'approche du bas. */
  const surDefilement = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    const { layoutMeasurement, contentOffset, contentSize } = e.nativeEvent;
    if (layoutMeasurement.height + contentOffset.y > contentSize.height - 600) {
      suite();
    }
  };

  const reessayer = () => {
    setErreur(false);
    setEssai((n) => n + 1);
  };

  return { page, erreur, reessayer, surDefilement };
}
