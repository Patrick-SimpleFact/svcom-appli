import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { appelApi } from '@/api/client';
import { useCompte } from '@/compte/compte';

/** Un favori (`GET /v1/moi/favoris`, API §8) : la séance choisie, sinon la prochaine du spectacle. */
export type Favori = {
  id: number;
  spectacle: { id: number; titre: string; image_url: string | null };
  representation: {
    id: number;
    date_locale: string;
    type: 'seance' | 'jour' | 'periode' | 'continu';
    complet: boolean;
    debut: string | null;
    lieu: { id: number | null; nom: string | null; ville: string | null };
  } | null;
  seance_choisie: boolean;
};

export type Favoris = { ce_soir: Favori[]; a_venir: Favori[]; passes: Favori[] };

export type Suivi = { id: number; type: 'lieu' | 'artiste'; cible: { id: number; nom: string | null; ville?: string | null }; nouveautes: number };

/** Une nouveauté d'un lieu ou d'un artiste suivi (F3.4) ; elle alimente l'encadré et le badge de l'onglet Favoris. */
export type Nouveaute = {
  id: number;
  type: 'nouveau_spectacle_lieu' | 'nouvelle_date_artiste' | 'rappel_jour_j';
  vue: boolean;
  cree_le: string;
  spectacle: { id: number; titre: string } | null;
  representation: { id: number; date_locale: string; debut: string | null; lieu: { id: number | null; nom: string | null; ville: string | null } } | null;
};

const VIDE: Favoris = { ce_soir: [], a_venir: [], passes: [] };

function charger() {
  return Promise.all([
    appelApi<Favoris>('/v1/moi/favoris'),
    appelApi<{ suivis: Suivi[] }>('/v1/moi/suivis'),
    appelApi<{ non_vues: number; nouveautes: Nouveaute[] }>('/v1/moi/nouveautes'),
  ]);
}

type Etat = {
  favoris: Favoris;
  suivis: Suivi[];
  nouveautes: Nouveaute[];
  nonVues: number;
  /** Identifiant du favori d'un spectacle, s'il y en a un (cœur de la fiche). */
  favoriDe: (spectacleId: number) => number | null;
  ajouter: (spectacleId: number, representationId?: number) => Promise<void>;
  retirer: (favoriId: number) => Promise<void>;
  suivre: (type: Suivi['type'], id: number) => Promise<Suivi | null>;
  nePlusSuivre: (suiviId: number) => Promise<void>;
  marquerVues: (ids?: number[]) => Promise<void>;
  recharger: () => Promise<void>;
};

const Contexte = createContext<Etat | null>(null);

export function useFavoris(): Etat {
  const etat = useContext(Contexte);
  if (!etat) {
    throw new Error('useFavoris hors de FavorisProvider');
  }

  return etat;
}

/** Favoris, suivis et nouveautés du compte connecté (F3) ; vides en mode invité. */
export function FavorisProvider({ children }: { children: ReactNode }) {
  const { utilisateur } = useCompte();
  const [favoris, setFavoris] = useState<Favoris>(VIDE);
  const [suivis, setSuivis] = useState<Suivi[]>([]);
  const [nouveautes, setNouveautes] = useState<{ non_vues: number; nouveautes: Nouveaute[] }>({ non_vues: 0, nouveautes: [] });

  const connecte = utilisateur !== null;

  const appliquer = useCallback(([f, s, n]: [Favoris, { suivis: Suivi[] }, { non_vues: number; nouveautes: Nouveaute[] }]) => {
    setFavoris(f);
    setSuivis(s.suivis);
    setNouveautes(n);
  }, []);

  const recharger = useCallback(() => charger().then(appliquer), [appliquer]);

  // À la connexion (et au lancement si une session est ouverte) ; en mode invité, la valeur exposée est vide.
  useEffect(() => {
    if (!connecte) {
      return;
    }
    let actif = true;
    charger()
      .then((r) => actif && appliquer(r))
      .catch(() => {});

    return () => {
      actif = false;
    };
  }, [connecte, appliquer]);

  const favoriDe = useCallback(
    (spectacleId: number) => [...favoris.ce_soir, ...favoris.a_venir, ...favoris.passes].find((f) => f.spectacle.id === spectacleId)?.id ?? null,
    [favoris],
  );

  const ajouter = useCallback(
    async (spectacleId: number, representationId?: number) => {
      await appelApi('/v1/moi/favoris', { methode: 'POST', corps: { spectacle_id: spectacleId, representation_id: representationId } });
      setFavoris(await appelApi<Favoris>('/v1/moi/favoris'));
    },
    [],
  );

  const retirer = useCallback(async (favoriId: number) => {
    // Retiré tout de suite à l'écran ; remis si le BO refuse.
    const avant = favoris;
    setFavoris({ ce_soir: avant.ce_soir.filter((f) => f.id !== favoriId), a_venir: avant.a_venir.filter((f) => f.id !== favoriId), passes: avant.passes.filter((f) => f.id !== favoriId) });
    try {
      await appelApi(`/v1/moi/favoris/${favoriId}`, { methode: 'DELETE' });
    } catch (e) {
      setFavoris(avant);
      throw e;
    }
  }, [favoris]);

  const suivre = useCallback(async (type: Suivi['type'], id: number) => {
    const r = await appelApi<{ suivi: Suivi; deja_suivi: boolean }>('/v1/moi/suivis', { methode: 'POST', corps: { type, id } });
    setSuivis((avant) => (avant.some((s) => s.id === r.suivi.id) ? avant : [r.suivi, ...avant]));

    return r.suivi;
  }, []);

  const nePlusSuivre = useCallback(async (suiviId: number) => {
    await appelApi(`/v1/moi/suivis/${suiviId}`, { methode: 'DELETE' });
    setSuivis((avant) => avant.filter((s) => s.id !== suiviId));
  }, []);

  const marquerVues = useCallback(async (ids?: number[]) => {
    await appelApi('/v1/moi/nouveautes/vues', { methode: 'POST', corps: ids ? { ids } : {} });
    setNouveautes((avant) => {
      const liste = avant.nouveautes.map((n) => (!ids || ids.includes(n.id) ? { ...n, vue: true } : n));

      return { non_vues: liste.filter((n) => !n.vue).length, nouveautes: liste };
    });
  }, []);

  const valeur = useMemo<Etat>(
    () => ({
      favoris: connecte ? favoris : VIDE,
      suivis: connecte ? suivis : [],
      nouveautes: connecte ? nouveautes.nouveautes : [],
      nonVues: connecte ? nouveautes.non_vues : 0,
      favoriDe,
      ajouter,
      retirer,
      suivre,
      nePlusSuivre,
      marquerVues,
      recharger,
    }),
    [connecte, favoris, suivis, nouveautes, favoriDe, ajouter, retirer, suivre, nePlusSuivre, marquerVues, recharger],
  );

  return <Contexte.Provider value={valeur}>{children}</Contexte.Provider>;
}
