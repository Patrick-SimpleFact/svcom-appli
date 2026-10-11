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

/** Quand ? (F4.3) ; sans valeur : toutes les dates à venir. `du`/`au` portent les jours envoyés à l'API. */
export type Quand = 'aujourdhui' | 'demain' | 'week_end' | 'date' | 'periode';
export type Moment = 'matinee' | 'apres_midi' | 'soiree';
export type Tri = 'date' | 'distance' | 'prix';

/** Ce que l'on cherche, avec les filtres de F4.3 ; sans lieu ni position : toute la France (API §4). */
export type Criteres = {
  q?: string;
  /** `lat`/`lon` : pour « En faire ma ville de recherche » (vue carte de l'accueil, M06). */
  ville?: { id: number; nom: string; lat?: number; lon?: number };
  /** « Autour de moi » : la position du téléphone, jamais enregistrée (F2.11). */
  position?: { lat: number; lon: number };
  /** Rayon autour de la ville ou de la position (10 km par défaut côté BO). */
  rayon?: number;
  genres?: number[];
  jeune_public?: boolean;
  quand?: Quand;
  du?: string;
  au?: string;
  moment?: Moment;
  /** 0 = gratuit ; sinon « moins de N € » (paliers du BO, F7.12). */
  prix_max?: number;
  masquer_complets?: boolean;
  tri?: Tri;
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
      ville_id: c.position ? undefined : c.ville?.id,
      lat: c.position?.lat,
      lon: c.position?.lon,
      rayon: c.ville || c.position ? c.rayon : undefined,
      genres: c.genres?.length ? c.genres : undefined,
      jeune_public: c.jeune_public ? 1 : undefined,
      du: c.du,
      au: c.au,
      moment: c.moment,
      gratuit: c.prix_max === 0 ? 1 : undefined,
      prix_max: c.prix_max ? c.prix_max : undefined,
      masquer_complets: c.masquer_complets ? 1 : undefined,
      tri: c.tri ?? 'date',
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

// ─── Filtres (F4.3) ────────────────────────────────────────────────────────────

const JOURS_LONGS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

function date(jour: string): Date {
  const [a, m, j] = jour.split('-').map(Number);

  return new Date(a, m - 1, j);
}

/** Jours envoyés à l'API pour un choix « Quand ? » (jour choisi ou période fournis par le calendrier). */
export function joursDe(quand: Quand, maintenant: Date, choisis: { du?: string; au?: string } = {}): { du?: string; au?: string } {
  const decale = (n: number) => jourDe(new Date(maintenant.getFullYear(), maintenant.getMonth(), maintenant.getDate() + n));
  switch (quand) {
    case 'aujourdhui':
      return { du: decale(0), au: decale(0) };
    case 'demain':
      return { du: decale(1), au: decale(1) };
    case 'week_end':
      return weekEnd(maintenant);
    case 'date':
      return { du: choisis.du, au: choisis.du };
    default:
      return { du: choisis.du, au: choisis.au ?? choisis.du };
  }
}

export const LIBELLES_MOMENTS: Record<Moment, string> = { matinee: 'Matinée', apres_midi: 'Après-midi', soiree: 'Soirée' };
export const LIBELLES_TRIS: Record<Tri, string> = { date: 'Par date', distance: 'Par distance', prix: 'Par prix' };

/** « Gratuit », « Moins de 15 € ». */
export function libellePrixMax(prix: number): string {
  return prix === 0 ? 'Gratuit' : `Moins de ${prix} €`;
}

/** Libellé de la puce « Quand ? ». */
export function libelleQuand(c: Criteres): string | null {
  switch (c.quand) {
    case 'aujourdhui':
      return 'Aujourd’hui';
    case 'demain':
      return 'Demain';
    case 'week_end':
      return 'Ce week-end';
    case 'date':
      return c.du ? `${JOURS_LONGS[date(c.du).getDay()].replace(/^./, (l) => l.toUpperCase())} ${date(c.du).getDate()} ${MOIS[date(c.du).getMonth()]}` : null;
    case 'periode':
      return c.du && c.au ? `Du ${date(c.du).getDate()} ${MOIS_COURTS[date(c.du).getMonth()]} au ${date(c.au).getDate()} ${MOIS_COURTS[date(c.au).getMonth()]}` : null;
    default:
      return null;
  }
}

export type Puce = { cle: 'ou' | 'quand' | 'genres' | 'moment' | 'prix' | 'complets'; libelle: string };

/** Filtres actifs, en puces retirables au-dessus des résultats (F4.3). */
export function puces(c: Criteres, libelleGenre: (id: number) => string): Puce[] {
  const liste: Puce[] = [];
  if (c.position || c.ville) {
    liste.push({ cle: 'ou', libelle: `${c.position ? 'Autour de moi' : c.ville!.nom} · ${(c.rayon ?? 10000) / 1000} km` });
  }
  const quand = libelleQuand(c);
  if (quand) {
    liste.push({ cle: 'quand', libelle: quand });
  }
  const genres = [...(c.genres ?? []).map(libelleGenre).filter(Boolean), ...(c.jeune_public ? ['Jeune public'] : [])];
  if (genres.length) {
    liste.push({ cle: 'genres', libelle: genres.join(', ') });
  }
  if (c.moment) {
    liste.push({ cle: 'moment', libelle: LIBELLES_MOMENTS[c.moment] });
  }
  if (c.prix_max !== undefined) {
    liste.push({ cle: 'prix', libelle: libellePrixMax(c.prix_max) });
  }
  if (c.masquer_complets) {
    liste.push({ cle: 'complets', libelle: 'Sans les complets' });
  }

  return liste;
}

/** Retire un filtre ; le texte cherché et le tri restent. */
export function retirer(c: Criteres, cle: Puce['cle']): Criteres {
  const { ...suite } = c;
  switch (cle) {
    case 'ou':
      delete suite.ville;
      delete suite.position;
      delete suite.rayon;
      break;
    case 'quand':
      delete suite.quand;
      delete suite.du;
      delete suite.au;
      break;
    case 'genres':
      delete suite.genres;
      delete suite.jeune_public;
      break;
    case 'moment':
      delete suite.moment;
      break;
    case 'prix':
      delete suite.prix_max;
      break;
    case 'complets':
      delete suite.masquer_complets;
      break;
  }

  return suite;
}

/** « Tout effacer » : il ne reste que le texte cherché (et le tri). */
export function toutEffacer(c: Criteres): Criteres {
  return { q: c.q, tri: c.tri, libelle: c.q ?? 'Tous les spectacles' };
}
