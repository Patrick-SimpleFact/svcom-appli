import AsyncStorage from '@react-native-async-storage/async-storage';

import { appelApi } from '@/api/client';
import { jourDe } from '@/autour/autour';
import type { CarteRecherche } from '@/fiche/pages';

/** Propositions pendant la frappe (F4.1, API §4) : 3 par type au plus. */
export type Propositions = {
  spectacles: { id: number; titre: string; sous_titre: string | null }[];
  artistes: { id: number; nom: string; sous_titre: string | null }[];
  lieux: { id: number; nom: string; sous_titre: string | null }[];
  villes: { id: number; nom: string; sous_titre: string | null; lat?: number; lon?: number }[];
};

export type Resultats = {
  total: number;
  total_plus: boolean;
  jours: { date: string; cartes: CarteRecherche[] }[];
  sans_prix_masques: number;
  /** « Vouliez-vous dire … ? » quand le texte ne correspond à rien (F4.5). */
  correction: string | null;
  /** Élargir : `{ rayon_m, total }` (50 km) ou `{ au, total }` (30 jours) (F4.5). */
  elargir: { rayon_m?: number; au?: string; total: number } | null;
  suivant: string | null;
};

/** Ce que l'on cherche ; sans lieu ni position : toute la France (API §4). */
export type Criteres = {
  q?: string;
  ville?: { id: number; nom: string };
  rayon?: number;
  genres?: number[];
  jeune_public?: boolean;
  du?: string;
  au?: string;
  /** Résumé lisible (« Avignon », « Ce week-end », « Danse »…), pour le titre et les recherches récentes. */
  libelle: string;
};

export function chargerPropositions(texte: string): Promise<Propositions> {
  return appelApi<Propositions>('/v1/recherche/propositions', { parametres: { q: texte } });
}

export function chercher(c: Criteres, suivant?: string | null): Promise<Resultats> {
  return appelApi<Resultats>('/v1/recherche', {
    parametres: {
      q: c.q,
      ville_id: c.ville?.id,
      rayon: c.rayon,
      genres: c.genres,
      jeune_public: c.jeune_public ? 1 : undefined,
      du: c.du,
      au: c.au,
      tri: 'date',
      suivant,
    },
  });
}

// ─── Recherches récentes (F4.1, F4.8 : elles restent sur le téléphone) ────────

export type Recente =
  | { type: 'texte'; libelle: string; criteres: Criteres }
  | { type: 'ville'; libelle: string; criteres: Criteres }
  | { type: 'lieu' | 'artiste' | 'spectacle'; libelle: string; id: number };

const CLE = 'spettacoli.recherches_recentes';
export const MAX_RECENTES = 10;

export async function lireRecentes(): Promise<Recente[]> {
  try {
    const liste = JSON.parse((await AsyncStorage.getItem(CLE)) ?? '[]') as Recente[];

    return Array.isArray(liste) ? liste.slice(0, MAX_RECENTES) : [];
  } catch {
    return [];
  }
}

/** La plus récente en tête, sans doublon (même type et même libellé), 10 au plus. */
export function ajouterRecente(liste: Recente[], r: Recente): Recente[] {
  return [r, ...liste.filter((x) => !(x.type === r.type && x.libelle.toLowerCase() === r.libelle.toLowerCase()))].slice(0, MAX_RECENTES);
}

export function enregistrerRecentes(liste: Recente[]) {
  return (liste.length ? AsyncStorage.setItem(CLE, JSON.stringify(liste)) : AsyncStorage.removeItem(CLE)).catch(() => {});
}

// ─── Raccourcis et mise en forme ──────────────────────────────────────────────

/** « Ce week-end » : du vendredi au dimanche en cours, ou les prochains (F2.2, F4.1). */
export function weekEnd(maintenant: Date): { du: string; au: string } {
  const jour = maintenant.getDay(); // 0 = dimanche
  const versVendredi = jour === 0 ? -2 : jour === 6 ? -1 : 5 - jour;
  const vendredi = new Date(maintenant.getFullYear(), maintenant.getMonth(), maintenant.getDate() + versVendredi);
  const dimanche = new Date(vendredi.getFullYear(), vendredi.getMonth(), vendredi.getDate() + 2);

  return { du: jourDe(vendredi), au: jourDe(dimanche) };
}

const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

/** Talon d'une carte de recherche : « sam. 17 » au-dessus de l'heure (F4.4). */
export function jourTalon(jour: string): string {
  const [a, m, j] = jour.split('-').map(Number);

  return `${JOURS[new Date(a, m - 1, j).getDay()]} ${j}`;
}

/** Villes pilotes proposées avant la frappe (maquette « Rechercher »). */
export const VILLES_PILOTES = ['Paris', 'Bordeaux', 'Avignon'];
