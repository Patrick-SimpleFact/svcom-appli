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

export type Recherche = { lat: number; lon: number; rayon_m: RayonM; suivant?: string | null };

/** Spectacles de ce soir autour d'une position ; la position n'est jamais enregistrée par le BO (F2.11). */
export function chargerAutour({ lat, lon, rayon_m, suivant }: Recherche): Promise<ReponseAutour> {
  return appelApi<ReponseAutour>('/v1/representations/autour', {
    parametres: { lat, lon, quand: 'ce_soir', rayon: rayon_m ?? 'auto', tri: 'heure', suivant },
  });
}

// ─── Mise en forme (heure du lieu, distances, mentions) ───────────────────────

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

/** Titre de l'accueil : « Ce soir » ou, avant 16 h, « Aujourd'hui » (F2.2). */
export function titreAccueil(maintenant: Date): string {
  return maintenant.getHours() < 16 ? 'Aujourd’hui,\nprès de vous' : 'Ce soir,\nprès de vous';
}

const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

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
