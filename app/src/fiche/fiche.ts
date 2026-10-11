import { appelApi, chaineParametres } from '@/api/client';
import { dateDuJour, heure } from '@/autour/autour';

/** Une billetterie qui vend la séance (API §5) ; `lien_sortie` passe par le BO, qui compte le clic puis redirige (API §6). */
export type Billetterie = {
  offre_id: number;
  source: string;
  /** `false` : site de l'organisateur (DATAtourisme, OpenAgenda), pas une billetterie. */
  billetterie: boolean;
  prix_min: number | null;
  prix_max: number | null;
  complet: boolean;
  recommandee: boolean;
  lien_sortie: string;
};

export type Seance = {
  representation_id: number;
  type: 'seance' | 'jour' | 'periode' | 'continu';
  debut: string;
  date_fin: string | null;
  salle: string | null;
  complet: boolean;
  billetteries: Billetterie[];
};

export type Fiche = {
  id: number;
  titre: string;
  image_url: string | null;
  genre: { id: number; slug: string; libelle: string } | null;
  classification: string | null;
  jeune_public: boolean;
  age_min: number | null;
  duree_minutes: number | null;
  festival: string | null;
  description: string | null;
  artistes: { id: number; nom: string; role: string | null }[];
  termine: boolean;
  lieu: {
    id: number;
    nom: string;
    adresse: string | null;
    code_postal: string | null;
    ville: string | null;
    lat: number | null;
    lon: number | null;
    position_approximative: boolean;
    telephone: string | null;
    site_web: string | null;
    distance_m: number | null;
  } | null;
  jour: string | null;
  seances: Seance[];
  prix: { min: number | null; max: number | null; gratuit: boolean };
  autres_dates: number;
  sources: string[];
  mentions: string[];
  mis_a_jour_le: string | null;
};

/** Fiche ouverte sur la séance touchée ; la position sert seulement à la distance (F5.1, F2.11). */
export function chargerFiche(id: number, representation?: number, position?: { lat: number; lon: number }): Promise<Fiche> {
  return appelApi<Fiche>(`/v1/spectacles/${id}`, { parametres: { representation, lat: position?.lat, lon: position?.lon } });
}

// ─── Mise en forme ─────────────────────────────────────────────────────────────

const JOURS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** « Samedi 17 octobre » (titre des séances, F5.2). */
export function jourLong(jour: string): string {
  const date = dateDuJour(jour);

  return `${JOURS[date.getDay()]} ${date.getDate() === 1 ? '1er' : date.getDate()} ${MOIS[date.getMonth()]}`;
}

/** « 1 h 20 », « 45 min », « 2 h ». */
export function duree(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) {
    return `${m} min`;
  }

  return m ? `${h} h ${String(m).padStart(2, '0')}` : `${h} h`;
}

const euros = (n: number) => `${Number.isInteger(n) ? n : n.toFixed(2).replace('.', ',')} €`;

/** « De 12 € à 25 € », « 15 € », « Gratuit » ; prix inconnu : « Tarifs sur la billetterie », jamais de prix inventé (F5.2). */
export function libellePrix(prix: Fiche['prix']): string {
  if (prix.gratuit) {
    return 'Gratuit';
  }
  if (prix.min === null) {
    return 'Tarifs sur la billetterie';
  }

  return prix.max !== null && prix.max > prix.min ? `De ${euros(prix.min)} à ${euros(prix.max)}` : euros(prix.min);
}

/** Prix d'une billetterie dans « Où réserver ? ». */
export function prixBilletterie(b: Billetterie): string {
  return b.prix_min === null ? '' : euros(b.prix_min);
}

/** « 12 min à pied » sous 2,5 km, sinon la distance en km (Accès, F5.2). */
export function trajet(metres: number | null): string | null {
  if (metres === null) {
    return null;
  }
  if (metres <= 2500) {
    return `${Math.max(1, Math.round(metres / 80))} min à pied`; // ≈ 4,8 km/h
  }

  return `à ${Math.round(metres / 1000)} km`;
}

/** « à 900 m », « à 12 km » (ligne du lieu, F5.2). */
export function aDistance(metres: number | null): string | null {
  if (metres === null) {
    return null;
  }

  return metres < 1000 ? `à ${Math.max(50, Math.round(metres / 50) * 50)} m` : `à ${(Math.round(metres / 100) / 10).toLocaleString('fr-FR')} km`;
}

/** « mis à jour le 02/10 à 6 h » (bas de fiche, F5.2). */
export function miseAJour(iso: string | null): string | null {
  if (!iso) {
    return null;
  }
  const d = new Date(iso);
  const minutes = d.getMinutes();

  return `mis à jour le ${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} à ${d.getHours()} h${minutes ? ` ${String(minutes).padStart(2, '0')}` : ''}`;
}

/** Libellé de la puce d'une séance : l'heure, ou la mention quand il n'y a pas d'horaire précis (F5.7). */
export function heureSeance(s: Seance): string {
  return s.type === 'seance' ? heure(s.debut) : '';
}

// ─── Réserver (F5.4) ───────────────────────────────────────────────────────────

export type Reservation =
  | { cas: 'reserver'; principale: Billetterie; autres: Billetterie[] }
  | { cas: 'entree_libre'; organisateur: Billetterie | null }
  | { cas: 'complet'; autreSeance: Seance | null }
  | { cas: 'termine' }
  | { cas: 'aucune' };

/** Le bouton du bas selon la séance choisie : billetterie recommandée (désignée par le BO), complet, entrée libre ou terminé. */
export function reservation(fiche: Fiche, seance: Seance | undefined): Reservation {
  if (fiche.termine || !seance) {
    return { cas: 'termine' };
  }
  const enVente = seance.billetteries.filter((b) => !b.complet);
  if (seance.complet || (seance.billetteries.length > 0 && enVente.length === 0)) {
    return { cas: 'complet', autreSeance: fiche.seances.find((s) => s !== seance && !s.complet) ?? null };
  }
  const billetteries = enVente.filter((b) => b.billetterie);
  if (fiche.prix.gratuit && billetteries.length === 0) {
    return { cas: 'entree_libre', organisateur: enVente[0] ?? null };
  }
  const principale = enVente.find((b) => b.recommandee) ?? enVente[0];
  if (!principale) {
    return { cas: 'aucune' };
  }

  return { cas: 'reserver', principale, autres: enVente.filter((b) => b !== principale) };
}

/** Texte du bouton principal : « Réserver sur BilletRéduc », ou « Voir sur le site de l'organisateur » (F5.4). */
export function texteBouton(b: Billetterie): string {
  return b.billetterie ? `Réserver sur ${b.source}` : 'Voir sur le site de l’organisateur';
}

/** D'où vient le clic, pour les statistiques des billetteries (API §6). */
export type Origine = 'liste_ce_soir' | 'recherche' | 'suggestion_auto' | 'suggestion_sponsorisee' | 'favori' | 'lien_partage' | 'page_lieu' | 'page_artiste' | 'fiche';

/** Lien de sortie complété avec l'origine, le bouton et la distance (le BO compte le clic puis redirige). */
export function lienSortie(b: Billetterie, origine: Origine, bouton: 'principal' | 'autre', distanceM: number | null): string {
  const suite = chaineParametres({ origine, bouton, distance_km: distanceM === null ? undefined : Math.round(distanceM / 100) / 10 }).slice(1);

  return `${b.lien_sortie}${b.lien_sortie.includes('?') ? '&' : '?'}${suite}`;
}
