import { appelApi } from '@/api/client';
import type { RayonM } from '@/gouts/gouts';

/** Réponse de `GET /v1/representations/autour` (API §3). */
export type Badge = 'complet' | 'gratuit' | 'horaire_a_confirmer' | 'derniere_places';

type SpectacleCarte = { id: number; titre: string; classification: string | null; genre_id: number; jeune_public: boolean };
type LieuCarte = { id: number; nom: string; position_approximative: boolean };

/** Une carte = un spectacle × un lieu × un jour, séances du jour regroupées (F2.5). */
export type Carte = {
  spectacle: SpectacleCarte;
  lieu: LieuCarte;
  distance_m: number;
  date_locale: string;
  type: 'seance';
  seances: { representation_id: number; debut: string; complet: boolean }[];
  badges: Badge[];
  gratuit: boolean;
};

/** Spectacle sans horaire précis : bloc « Toute la journée » (F2.2). */
export type CarteJournee = {
  spectacle: SpectacleCarte;
  lieu: LieuCarte;
  distance_m: number;
  representation_id: number;
  type: 'jour' | 'periode' | 'continu';
  date_locale: string;
  date_fin: string | null;
  fin: string | null;
  badges: Badge[];
  gratuit: boolean;
};

export type ReponseAutour = {
  rayon_retenu_m: number;
  couverture_faible: boolean;
  total: number;
  cartes: Carte[];
  toute_la_journee: CarteJournee[];
  suivant: string | null;
};

/** Quand ? (F2.2) : puces Ce soir / Demain / Week-end, ou un jour choisi (AAAA-MM-JJ, jusqu'à 3 mois). */
export type Quand = 'ce_soir' | 'demain' | 'week_end' | string;

/** Quoi ? (F2.3) : Tout, Pour vous (goûts), un genre, ou Jeune public (un public, pas un genre). */
export type Onglet = 'tout' | 'pour_vous' | 'jeune_public' | number;

/** Où ? (F2.1) : la position du téléphone ou une ville choisie. */
export type Point = { lat: number; lon: number } | { ville_id: number };

export type Recherche = { point: Point; quand: Quand; onglet: Onglet; rayon_m: RayonM; gouts: number[]; suivant?: string | null };

/** Paramètres de `GET /v1/representations/autour` (API §3) ; la position n'est jamais enregistrée par le BO (F2.11). */
export function parametresAutour({ point, quand, onglet, rayon_m, gouts, suivant }: Recherche) {
  return {
    ...point,
    quand,
    rayon: rayon_m ?? 'auto',
    tri: 'heure',
    genres: typeof onglet === 'number' ? [onglet] : undefined,
    pour_vous: onglet === 'pour_vous' ? 1 : undefined,
    gouts: onglet === 'pour_vous' ? gouts : undefined,
    jeune_public: onglet === 'jeune_public' ? 1 : undefined,
    suivant,
  };
}

export function chargerAutour(recherche: Recherche): Promise<ReponseAutour> {
  return appelApi<ReponseAutour>('/v1/representations/autour', { parametres: parametresAutour(recherche) });
}

// ─── Mise en forme (heure du lieu, distances, mentions) ───────────────────────

const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

/** « 19:30 » : l'heure est lue telle que le BO l'envoie, déjà à l'heure du lieu (et non du téléphone). */
export function heure(iso: string): string {
  return iso.slice(11, 16);
}

/** Talon de la carte : première séance, puis « + 21:15 » pour les suivantes (F2.5). */
export function talon(carte: Carte): { heure: string; suite: string } {
  const [premiere, ...autres] = carte.seances;

  return { heure: premiere ? heure(premiere.debut) : '', suite: autres.length ? `+ ${autres.map((s) => heure(s.debut)).join(', ')}` : '' };
}

/** « 400 m », « 1,2 km », « 12 km » ; « ≈ » si la position du lieu est approximative (F2.5, F5.7). */
export function distance(metres: number, approximative = false): string {
  const texte =
    metres < 1000
      ? `${Math.max(50, Math.round(metres / 50) * 50)} m`
      : metres < 10000
        ? `${(Math.round(metres / 100) / 10).toLocaleString('fr-FR')} km`
        : `${Math.round(metres / 1000)} km`;

  return approximative ? `≈ ${texte}` : texte;
}

/** Compteur sous les onglets : « 12 spectacles à moins de 5 km » (F2.4). */
export function compteur(total: number, rayonM: number): string {
  return `${total} spectacle${total > 1 ? 's' : ''} à moins de ${distance(rayonM)}`;
}

const JOURS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
const MOIS_LONGS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** « 2026-10-17 » → date locale (sans décalage de fuseau). */
export function dateDuJour(jour: string): Date {
  const [a, m, j] = jour.split('-').map(Number);

  return new Date(a, m - 1, j);
}

/** Date → « 2026-10-17 ». */
export function jourDe(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Titre de l'accueil selon la puce (F2.2) : « Ce soir » (avant 16 h : « Aujourd'hui »), « Demain », « Ce week-end », « Samedi 17 octobre ». */
export function titreAccueil(maintenant: Date, quand: Quand = 'ce_soir'): string {
  switch (quand) {
    case 'ce_soir':
      return maintenant.getHours() < 16 ? 'Aujourd’hui,\nprès de vous' : 'Ce soir,\nprès de vous';
    case 'demain':
      return 'Demain,\nprès de vous';
    case 'week_end':
      return 'Ce week-end,\nprès de vous';
    default: {
      const date = dateDuJour(quand);

      return `${JOURS[date.getDay()]} ${date.getDate() === 1 ? '1er' : date.getDate()} ${MOIS_LONGS[date.getMonth()]},\nprès de vous`;
    }
  }
}

/** Libellé court de la puce d'un jour choisi : « Sam. 17 oct. ». */
export function libelleJour(jour: string): string {
  const date = dateDuJour(jour);

  return `${JOURS[date.getDay()].slice(0, 3)}. ${date.getDate()} ${MOIS[date.getMonth()]}`;
}

/** Codes pays couverts : la métropole et l'outre-mer (F2.1). */
const PAYS_COUVERTS = ['FR', 'GP', 'MQ', 'GF', 'RE', 'YT', 'PM', 'BL', 'MF', 'NC', 'PF', 'WF'];

export function enFrance(codePays: string | null | undefined): boolean {
  return !codePays || PAYS_COUVERTS.includes(codePays.toUpperCase());
}

/** Distance à vol d'oiseau en mètres (bandeau « Vous avez bougé » au-delà de 1 km, F2.1). */
export function ecartMetres(a: { lat: number; lon: number }, b: { lat: number; lon: number }): number {
  const rad = (d: number) => (d * Math.PI) / 180;
  const h = Math.sin(rad(b.lat - a.lat) / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(rad(b.lon - a.lon) / 2) ** 2;

  return 2 * 6371000 * Math.asin(Math.sqrt(h));
}

function jourMois(date: string): string {
  const [, mois, jour] = date.split('-').map(Number);

  return `${jour === 1 ? '1er' : jour} ${MOIS[mois - 1]}`;
}

/** Mention toujours affichée sur une carte sans horaire précis, pour vérifier avant de se déplacer (F2.2). */
export function mentionJournee(carte: CarteJournee): string {
  switch (carte.type) {
    case 'periode':
      return carte.date_fin ? `Du ${jourMois(carte.date_locale)} au ${jourMois(carte.date_fin)} · horaires à confirmer` : 'Horaires à confirmer auprès du lieu';
    case 'continu':
      return carte.fin ? `En continu · jusqu’à ${heure(carte.fin).replace(':', ' h ').replace(' h 00', ' h')}` : 'En continu · horaires à confirmer';
    default:
      return 'Horaire à confirmer auprès du lieu';
  }
}

/** Une carte est complète quand toutes ses séances le sont : grisée, en fin de liste, « Complet » écrit en clair (F2.5). */
export function estComplete(carte: Carte): boolean {
  return carte.badges.includes('complet') || (carte.seances.length > 0 && carte.seances.every((s) => s.complet));
}
