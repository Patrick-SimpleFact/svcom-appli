import { appelApi } from '@/api/client';
import type { Badge, Carte, CarteJournee } from '@/autour/autour';
import type { Fiche } from '@/fiche/fiche';

/** Carte au format de la recherche (API §4), utilisée par les pages lieu et artiste (API §5). */
export type CarteRecherche = {
  spectacle: Carte['spectacle'];
  lieu: Carte['lieu'] & { ville?: string | null };
  distance_m: number | null;
  date_locale: string;
  date_fin: string | null;
  type: 'seance' | 'jour' | 'periode' | 'continu';
  seances: Carte['seances'];
  representation_id?: number;
  prix_min: number | null;
  badges: Badge[];
  gratuit: boolean;
};

type Dates = { total: number; total_plus: boolean; jours: { date: string; cartes: CarteRecherche[] }[]; suivant: string | null };

type LieuDetaille = NonNullable<Fiche['lieu']>;

export type PageLieu = LieuDetaille & Dates & { suivi: boolean | null };
export type PageArtiste = Dates & { id: number; nom: string; type: string; image_url: string | null; suivi: boolean | null };

/** « Autres dates » (F5.3) : toutes les représentations à venir, regroupées par lieu, le plus proche d'abord. */
export type AutresDates = {
  lieux: {
    lieu: LieuDetaille;
    dates: number;
    representations: { representation_id: number; date_locale: string; date_fin: string | null; type: CarteRecherche['type']; debut: string | null; complet: boolean }[];
  }[];
};

type Position = { lat: number; lon: number } | undefined;

export function chargerLieu(id: number, position: Position, suivant?: string | null): Promise<PageLieu> {
  return appelApi<PageLieu>(`/v1/lieux/${id}`, { parametres: { lat: position?.lat, lon: position?.lon, suivant } });
}

export function chargerArtiste(id: number, position: Position, suivant?: string | null): Promise<PageArtiste> {
  return appelApi<PageArtiste>(`/v1/artistes/${id}`, { parametres: { lat: position?.lat, lon: position?.lon, suivant } });
}

export function chargerAutresDates(spectacleId: number, position: Position): Promise<AutresDates> {
  return appelApi<AutresDates>(`/v1/spectacles/${spectacleId}/representations`, { parametres: { lat: position?.lat, lon: position?.lon } });
}

/** Une carte de recherche, affichée comme celles de l'accueil : à l'heure (talon), ou sans horaire précis (mention). */
export function versCarte(c: CarteRecherche): { horaire: Carte } | { journee: CarteJournee } {
  const base = { spectacle: c.spectacle, lieu: c.lieu, distance_m: c.distance_m, date_locale: c.date_locale, badges: c.badges, gratuit: c.gratuit };
  if (c.type === 'seance' && c.seances.length) {
    return { horaire: { ...base, type: 'seance', seances: c.seances } };
  }

  return { journee: { ...base, type: c.type === 'seance' ? 'jour' : c.type, representation_id: c.representation_id ?? c.seances[0]?.representation_id ?? 0, date_fin: c.date_fin, fin: null } };
}

/** Ajoute une page de dates aux précédentes, en fusionnant le jour à cheval entre deux pages. */
export function ajouterJours(avant: Dates['jours'], suite: Dates['jours']): Dates['jours'] {
  const jours = avant.map((j) => ({ ...j, cartes: [...j.cartes] }));
  for (const jour of suite) {
    const dernier = jours[jours.length - 1];
    if (dernier?.date === jour.date) {
      dernier.cartes.push(...jour.cartes);
    } else {
      jours.push(jour);
    }
  }

  return jours;
}

const JOURS_COURTS = ['Dim.', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.'];
const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

function jourCourt(jour: string): string {
  const [a, m, j] = jour.split('-').map(Number);
  const date = new Date(a, m - 1, j);

  return `${JOURS_COURTS[date.getDay()]} ${j === 1 ? '1er' : j} ${MOIS_COURTS[m - 1]}`;
}

/** Une date de « Autres dates » : « Sam. 17 oct. · 19:30 », « Du 1er oct. au 18 déc. », « Sam. 17 oct. · horaire à confirmer ». */
export function libelleDate(r: AutresDates['lieux'][number]['representations'][number]): string {
  if (r.type === 'seance' && r.debut) {
    return `${jourCourt(r.date_locale)} · ${r.debut.slice(11, 16)}`;
  }
  if (r.date_fin && r.date_fin !== r.date_locale) {
    return `Du ${jourCourt(r.date_locale).slice(5)} au ${jourCourt(r.date_fin).slice(5)}`;
  }

  return `${jourCourt(r.date_locale)} · horaire à confirmer`;
}
