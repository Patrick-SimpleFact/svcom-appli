import { appelApi } from '@/api/client';
import type { RayonM } from '@/gouts/gouts';

/** Réponse de `GET /v1/representations/autour` (API §3). */
export type Badge = 'complet' | 'gratuit' | 'horaire_a_confirmer' | 'derniere_places';

type SpectacleCarte = { id: number; titre: string; classification: string | null; genre_id: number; jeune_public: boolean };
type LieuCarte = { id: number; nom: string; position_approximative: boolean };

/** Une carte = un spectacle × un lieu × un jour, séances du jour regroupées (F2.5). */
export type Carte = {
  spectacle: SpectacleCarte;
  /** `ville` : donnée par la recherche, affichée à la place de la distance quand elle est inconnue. */
  lieu: LieuCarte & { ville?: string | null };
  /** `null` sans position (recherche dans toute la France, page d'un lieu ouverte sans position). */
  distance_m: number | null;
  date_locale: string;
  type: 'seance';
  seances: { representation_id: number; debut: string; complet: boolean }[];
  badges: Badge[];
  gratuit: boolean;
};

/** Spectacle sans horaire précis : bloc « Toute la journée » (F2.2). */
export type CarteJournee = {
  spectacle: SpectacleCarte;
  lieu: LieuCarte & { ville?: string | null };
  distance_m: number | null;
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
  /** Rempli quand la fenêtre est vide : « 4 spectacles ce week-end » (F2.7). */
  suggestion_date: { quand: Quand; nombre: number } | null;
  /** Rempli quand un filtre de genre ne donne rien : « Voir tous les genres (12) » (F2.7). */
  total_tous_genres: number | null;
  /** Fin de la dernière collecte réussie : au-delà de 36 h, on prévient (F2.8). */
  programme_mis_a_jour_le: string | null;
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

/** Ordre de la liste : heure de la 1re séance (par défaut) ou distance ; les complets restent en fin de liste (F2.5). */
export type TriAutour = 'heure' | 'distance';

export type Recherche = { point: Point; quand: Quand; onglet: Onglet; rayon_m: RayonM; gouts: number[]; tri?: TriAutour; suivant?: string | null };

/** Paramètres de `GET /v1/representations/autour` (API §3) ; la position n'est jamais enregistrée par le BO (F2.11). */
export function parametresAutour({ point, quand, onglet, rayon_m, gouts, tri, suivant }: Recherche) {
  return {
    ...point,
    quand,
    rayon: rayon_m ?? 'auto',
    tri: tri ?? 'heure',
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

/** Compteur sous les onglets : « 12 spectacles à moins de 5 km » (F2.4) ; espaces insécables : « 10 km » n'est jamais coupé. */
export function compteur(total: number, rayonM: number): string {
  return `${total}\u00a0spectacle${total > 1 ? 's' : ''} à moins de ${distance(rayonM).replace(' ', '\u00a0')}`;
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

/** « 2026-10-24 » → « 24 oct. » (« 1er » le premier du mois). */
export function jourMois(date: string): string {
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

// ─── Couverture faible, résultats vides et fraîcheur (F2.7, F2.8) ─────────────

/** « ce soir », « demain », « ce week-end », « le 17 octobre » : la fenêtre dans une phrase. */
export function moment(quand: Quand): string {
  switch (quand) {
    case 'ce_soir':
      return 'ce soir';
    case 'demain':
      return 'demain';
    case 'week_end':
      return 'ce week-end';
    default: {
      const date = dateDuJour(quand);

      return `le ${date.getDate() === 1 ? '1er' : date.getDate()} ${MOIS_LONGS[date.getMonth()]}`;
    }
  }
}

/** Ce qu'on dit quand la liste est vide (F2.7) ; jamais d'écran vide sans explication. */
export type Vide =
  | { cas: 'genre'; texte: string; action: string }
  | { cas: 'date'; texte: string; action: string; quand: Quand }
  | { cas: 'rien'; texte: string };

export function messageVide(reponse: ReponseAutour, quand: Quand, genre: string | null): Vide | null {
  if (reponse.total > 0) {
    return null;
  }
  if (genre && reponse.total_tous_genres) {
    return { cas: 'genre', texte: `Pas de ${genre.toLowerCase()} ${moment(quand)} près d’ici.`, action: `Voir tous les genres (${reponse.total_tous_genres})` };
  }
  if (reponse.suggestion_date && reponse.suggestion_date.nombre > 0) {
    const { nombre, quand: autre } = reponse.suggestion_date;

    return { cas: 'date', texte: `Rien ${moment(quand)} près d’ici.`, action: `${nombre} spectacle${nombre > 1 ? 's' : ''} ${moment(autre)} → Voir`, quand: autre };
  }

  return { cas: 'rien', texte: `Aucun spectacle ${moment(quand)} à moins de 50 km.` };
}

/** Données du BO de plus de 36 h : « Programme mis à jour il y a 2 jours. » (F2.8) ; `null` si elles sont fraîches ou inconnues. */
export function programmeAncien(misAJourLe: string | null, maintenant: Date): string | null {
  if (!misAJourLe) {
    return null;
  }
  const heures = (maintenant.getTime() - new Date(misAJourLe).getTime()) / 3_600_000;
  if (heures <= 36) {
    return null;
  }
  const jours = Math.max(1, Math.round(heures / 24));

  return `Programme mis à jour il y a ${jours} jour${jours > 1 ? 's' : ''}. Pensez à vérifier auprès de la salle.`;
}

/** « il y a 5 min », « il y a 2 h », « il y a 3 jours » (bandeau hors ligne, F2.8). */
export function ilYA(depuis: number, maintenant: number): string {
  const minutes = Math.max(0, Math.round((maintenant - depuis) / 60_000));
  if (minutes < 1) {
    return 'à l’instant';
  }
  if (minutes < 60) {
    return `il y a ${minutes} min`;
  }
  const heures = Math.round(minutes / 60);
  if (heures < 24) {
    return `il y a ${heures} h`;
  }
  const jours = Math.round(heures / 24);

  return `il y a ${jours} jour${jours > 1 ? 's' : ''}`;
}

/** Étape de l'attente d'un chargement (F2.8) selon les délais du BO (par défaut 3, 8 et 20 s). */
export type Attente = 'squelettes' | 'lent' | 'tres_lent' | 'echec';

export function etapeAttente(ecouleS: number, [lent, tresLent, echec]: number[] = [3, 8, 20]): Attente {
  if (ecouleS >= echec) {
    return 'echec';
  }
  if (ecouleS >= tresLent) {
    return 'tres_lent';
  }

  return ecouleS >= lent ? 'lent' : 'squelettes';
}

// ─── Vue carte (F2.6) ──────────────────────────────────────────────────────────

/** Un repère par lieu, avec le nombre de spectacles (`GET /v1/lieux/carte`, API §3). */
export type Repere = { lieu_id: number; nom: string; lat: number; lon: number; nombre: number };

export type Region = { latitude: number; longitude: number; latitudeDelta: number; longitudeDelta: number };

/** Région affichée autour d'un centre, assez large pour le rayon retenu par la liste. */
export function regionAutour(centre: { lat: number; lon: number }, rayonM: number): Region {
  const delta = Math.max(0.01, ((rayonM * 2.2) / 111_000));

  return { latitude: centre.lat, longitude: centre.lon, latitudeDelta: delta, longitudeDelta: delta / Math.cos((centre.lat * Math.PI) / 180) };
}

/** Bornes d'une région, au format de l'API (degrés). */
export function bornes(region: Region) {
  return {
    sud: +(region.latitude - region.latitudeDelta / 2).toFixed(5),
    nord: +(region.latitude + region.latitudeDelta / 2).toFixed(5),
    ouest: +(region.longitude - region.longitudeDelta / 2).toFixed(5),
    est: +(region.longitude + region.longitudeDelta / 2).toFixed(5),
  };
}

/** Mêmes filtres que la liste ; « Pour vous » sans compte = les genres des goûts. */
export function chargerReperes(region: Region, { quand, onglet, gouts }: Pick<Recherche, 'quand' | 'onglet' | 'gouts'>): Promise<Repere[]> {
  const genres = typeof onglet === 'number' ? [onglet] : onglet === 'pour_vous' ? gouts : undefined;

  return appelApi<{ reperes: Repere[] }>('/v1/lieux/carte', {
    parametres: { ...bornes(region), quand, genres, jeune_public: onglet === 'jeune_public' ? 1 : undefined },
  }).then((r) => r.reperes);
}

/** La carte a-t-elle assez bougé pour proposer « Rechercher dans cette zone » ? (un quart de la largeur, ou un zoom marqué) */
export function zoneDeplacee(avant: Region, apres: Region): boolean {
  return (
    Math.abs(apres.latitude - avant.latitude) > avant.latitudeDelta / 4 ||
    Math.abs(apres.longitude - avant.longitude) > avant.longitudeDelta / 4 ||
    Math.abs(Math.log(apres.latitudeDelta / avant.latitudeDelta)) > Math.log(1.5)
  );
}
