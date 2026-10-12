import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Network from 'expo-network';
import { useEffect } from 'react';
import { AppState } from 'react-native';

import { appelApi, ErreurApi } from '@/api/client';

/** Un événement d'usage (API §11) : anonyme, sans position ni e-mail (F2.11). */
export type Evenement = { type: string; horodatage: string; ville_id?: number; donnees?: Record<string, string | number | boolean | null> };

const CLE = 'spettacoli.evenements_en_attente';

/** Le BO refuse plus de 200 événements par lot (API §11). */
export const MAX_PAR_LOT = 200;

/** Hors ligne longtemps : on garde les 1 000 derniers, les plus anciens sont abandonnés. */
export const MAX_EN_ATTENTE = 1000;

/** Le BO ignore les événements de plus de 7 jours : inutile de les envoyer. */
export const JOURS_RETARD_MAX = 7;

/** Un lot toutes les 30 secondes au plus, et dès que l'app passe en arrière-plan (API §11). */
export const INTERVALLE_ENVOI_MS = 30_000;

let file: Evenement[] = [];
let relue: Promise<void> | null = null;
let envoiEnCours = false;

/** Où se trouve l'utilisateur : ville choisie (envoyée avec l'événement) ou position, arrondie à ≈ 1 km et envoyée avec le lot. */
let lieu: { ville_id: number } | { lat: number; lon: number } | null = null;

/** Premier clic sur une carte (KPI « < 30 s », F2.11) : mesuré une fois par ouverture, depuis l'affichage de l'accueil. */
let accueilAfficheLe: number | null = null;

/** Relit les événements restés en attente (app fermée hors ligne) ; une seule fois. */
function relire(): Promise<void> {
  relue ??= AsyncStorage.getItem(CLE)
    .then((json) => {
      const anciens = JSON.parse(json ?? '[]') as Evenement[];
      file = [...anciens, ...file].slice(-MAX_EN_ATTENTE);
    })
    .catch(() => {});

  return relue;
}

/** Écrit la file sur le téléphone, une fois les anciens événements relus (sinon ils seraient écrasés). */
function sauver() {
  relire().then(() => AsyncStorage.setItem(CLE, JSON.stringify(file))).catch(() => {});
}

/** Note un événement ; il partira avec le prochain lot. Ne lève jamais d'erreur : la mesure ne doit rien bloquer. */
export function mesurer(type: string, donnees?: Evenement['donnees']) {
  const evenement: Evenement = { type, horodatage: new Date().toISOString() };
  if (lieu && 'ville_id' in lieu) {
    evenement.ville_id = lieu.ville_id;
  }
  if (donnees && Object.keys(donnees).length) {
    evenement.donnees = donnees;
  }
  file = [...file, evenement].slice(-MAX_EN_ATTENTE);
  sauver();
}

/** L'accueil dit où il cherche : les statistiques sont faites par ville (la position n'est jamais enregistrée par le BO). */
export function definirLieuMesure(nouveau: { ville_id: number } | { lat: number; lon: number } | null) {
  lieu = nouveau && 'lat' in nouveau ? { lat: Math.round(nouveau.lat * 100) / 100, lon: Math.round(nouveau.lon * 100) / 100 } : nouveau;
}

/** L'accueil affiche ses spectacles : départ du chronomètre du premier clic, une fois par ouverture. */
export function accueilAffiche() {
  if (accueilAfficheLe === null) {
    accueilAfficheLe = Date.now();
    mesurer('accueil_affiche');
  }
}

/** Clic sur une carte de l'accueil : le premier depuis l'ouverture donne le temps de choix (F2.11). */
export function carteCliquee() {
  if (accueilAfficheLe !== null && accueilAfficheLe > 0) {
    mesurer('premier_clic_carte', { secondes: Math.round((Date.now() - accueilAfficheLe) / 100) / 10 });
    accueilAfficheLe = -1;
  }
}

/** Nouvelle ouverture (lancement ou retour au premier plan) : le chronomètre du premier clic repart. */
function ouverture() {
  accueilAfficheLe = null;
  mesurer('ouverture');
}

/** Filtres de la recherche (F4.3) qui ont changé entre deux recherches : un événement `filtre_utilise` par filtre. */
export function filtresModifies<T extends object>(avant: T, apres: T, filtres: readonly (keyof T)[]): string[] {
  return filtres.filter((f) => JSON.stringify(avant[f] ?? null) !== JSON.stringify(apres[f] ?? null)).map(String);
}

/** Garde les événements que le BO acceptera encore (moins de 7 jours). */
export function encoreValables(evenements: Evenement[], maintenant: number): Evenement[] {
  const limite = maintenant - JOURS_RETARD_MAX * 24 * 60 * 60 * 1000;

  return evenements.filter((e) => Date.parse(e.horodatage) > limite);
}

/**
 * Envoie un lot (200 au plus). Hors ligne : rien n'est envoyé, le lot attend. Si le BO ne répond pas, les événements restent
 * en attente ; s'il refuse le lot (422), il est abandonné pour ne pas bloquer la file.
 */
export async function envoyerLot(): Promise<void> {
  await relire();
  file = encoreValables(file, Date.now());
  if (envoiEnCours || file.length === 0) {
    return;
  }

  const reseau = await Network.getNetworkStateAsync().catch(() => null);
  if (reseau && (reseau.isConnected === false || reseau.isInternetReachable === false)) {
    return;
  }

  envoiEnCours = true;
  const lot = file.slice(0, MAX_PAR_LOT);
  try {
    await appelApi('/v1/evenements', { methode: 'POST', corps: { ...(lieu && 'lat' in lieu ? lieu : {}), evenements: lot } });
    file = file.slice(lot.length);
  } catch (erreur) {
    if (erreur instanceof ErreurApi && erreur.statut === 422) {
      file = file.slice(lot.length);
    }
  } finally {
    envoiEnCours = false;
    sauver();
  }
}

/** À monter une fois à la racine de l'app : ouverture, envois toutes les 30 s et au passage en arrière-plan. */
export function useMesure() {
  useEffect(() => {
    relire().then(ouverture);
    let etat = AppState.currentState;

    const minuteur = setInterval(() => envoyerLot(), INTERVALLE_ENVOI_MS);
    const abonnement = AppState.addEventListener('change', (suivant) => {
      if (suivant === 'background') {
        envoyerLot();
      } else if (suivant === 'active' && etat === 'background') {
        ouverture();
      }
      etat = suivant;
    });

    return () => {
      clearInterval(minuteur);
      abonnement.remove();
    };
  }, []);
}

/** Pour les tests uniquement. */
export function _reinitialiserMesure() {
  file = [];
  relue = null;
  envoiEnCours = false;
  lieu = null;
  accueilAfficheLe = null;
}

export function _fileMesure() {
  return file;
}
