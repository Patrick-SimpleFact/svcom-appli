import * as Location from 'expo-location';
import { useCallback, useEffect, useRef, useState } from 'react';

import { appelApi } from '@/api/client';
import { ecartMetres } from '@/autour/autour';

/** `approximative` : le GPS n'a pas répondu à temps, on part de la dernière position connue (F2.8). */
export type Position = { lat: number; lon: number; libelle: string | null; pays: string | null; approximative: boolean };

/**
 * Où cherche-t-on ? (F2.1) — `a_expliquer` : la phrase d'explication s'affiche avant la demande système ;
 * `refusee` : localisation refusée ou indisponible (écran « Où êtes-vous ? »).
 */
export type EtatPosition = { etat: 'verification' | 'a_expliquer' | 'recherche' | 'refusee' } | { etat: 'trouvee'; position: Position };

/** Délai au-delà duquel on se contente de la dernière position connue (F2.8 : localisation lente). */
const DELAI_GPS_MS = 5000;

/**
 * Position approximative : on attend encore le GPS jusqu'à ce délai. Android dépasse souvent les 5 s ; sans cela,
 * « Position approximative » restait affiché alors que le GPS répondait 2 ou 3 s plus tard (29/10/2026).
 */
export const DELAI_GPS_TARDIF_MS = 30000;

/** GPS tardif à moins de cette distance : le bandeau disparaît sans recharger la liste ; au-delà, la position est remplacée. */
export const ECART_SANS_RECHARGEMENT_M = 300;

type Point = { lat: number; lon: number };

const delai = (ms: number) => new Promise<null>((fin) => setTimeout(() => fin(null), ms));

/** « Avignon · Centre » : commune et quartier quand le téléphone les connaît (F2.1). */
export function libelleLieu(adresse: Pick<Location.LocationGeocodedAddress, 'city' | 'district' | 'subregion'> | undefined): string | null {
  const commune = adresse?.city ?? adresse?.subregion ?? null;
  if (!commune) {
    return null;
  }

  return adresse?.district && adresse.district !== commune ? `${commune} · ${adresse.district}` : commune;
}

/** Coordonnées seules (sans le nom de la commune) : GPS, ou la dernière position connue s'il tarde. */
export async function coordonnees(): Promise<{ lat: number; lon: number; approximative: boolean } | null> {
  const actuelle = await Promise.race([
    Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }).catch(() => null),
    new Promise<null>((fin) => setTimeout(() => fin(null), DELAI_GPS_MS)),
  ]);
  const coords = (actuelle ?? (await Location.getLastKnownPositionAsync().catch(() => null)))?.coords;

  return coords ? { lat: coords.latitude, lon: coords.longitude, approximative: !actuelle } : null;
}

/**
 * Nom de la commune donné par le BO (`GET /v1/commune`), quand le téléphone ne le connaît pas : le géocodage d'Android
 * reste parfois muet (« Ma position » affiché alors que la position est bonne, 29/10/2026). Réseau ou BO en panne → null.
 */
export async function communeDuServeur(point: { lat: number; lon: number }): Promise<string | null> {
  return appelApi<{ commune: string | null }>('/v1/commune', { parametres: { lat: point.lat, lon: point.lon } })
    .then((r) => r.commune ?? null)
    .catch(() => null);
}

/** Nom de la commune (téléphone, sinon BO) et pays d'une position. */
async function nommer(point: Point): Promise<Pick<Position, 'libelle' | 'pays'>> {
  const [adresse] = await Location.reverseGeocodeAsync({ latitude: point.lat, longitude: point.lon }).catch(() => []);
  const libelle = libelleLieu(adresse) ?? (await communeDuServeur(point));

  return { libelle, pays: adresse?.isoCountryCode ?? null };
}

/**
 * Position de l'accueil. GPS trop lent : dernière position connue (approximative), et `tardive` = la réponse du GPS
 * si elle arrive dans les 30 s. Sans dernière position connue, on attend directement ces 30 s.
 */
async function localiser(): Promise<{ position: Position; tardive: Promise<Point | null> | null } | null> {
  const gps: Promise<Point | null> = Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced })
    .then((p) => ({ lat: p.coords.latitude, lon: p.coords.longitude }))
    .catch(() => null);
  const rapide = await Promise.race([gps, delai(DELAI_GPS_MS)]);
  const derniere = rapide ? null : (await Location.getLastKnownPositionAsync().catch(() => null))?.coords;
  const tardive = rapide ? null : Promise.race([gps, delai(DELAI_GPS_TARDIF_MS - DELAI_GPS_MS)]);
  const point = rapide ?? (derniere ? { lat: derniere.latitude, lon: derniere.longitude } : await tardive);
  if (!point) {
    return null;
  }

  const approximative = !rapide && !!derniere;

  return { position: { ...point, ...(await nommer(point)), approximative }, tardive: approximative ? tardive : null };
}

/** Position une fois le GPS tardif arrivé : tout près, on garde le point (pas de rechargement) ; sinon, on le remplace. */
export async function positionPrecisee(avant: Position, gps: Point, nommerPoint: (p: Point) => Promise<Pick<Position, 'libelle' | 'pays'>> = nommer): Promise<Position> {
  if (ecartMetres(avant, gps) < ECART_SANS_RECHARGEMENT_M) {
    return { ...avant, approximative: false };
  }

  return { ...gps, ...(await nommerPoint(gps)), approximative: false };
}

export function usePosition() {
  const [etat, setEtat] = useState<EtatPosition>({ etat: 'verification' });

  // Numéro de la dernière recherche : un GPS tardif d'une recherche dépassée (« Actualiser ») est ignoré.
  const recherche = useRef(0);

  const chercher = useCallback(async () => {
    const numero = ++recherche.current;
    setEtat({ etat: 'recherche' });
    const resultat = await localiser();
    if (numero !== recherche.current) {
      return;
    }
    setEtat(resultat ? { etat: 'trouvee', position: resultat.position } : { etat: 'refusee' });

    const gps = resultat?.tardive ? await resultat.tardive : null;
    if (!resultat || !gps) {
      return;
    }
    const precisee = await positionPrecisee(resultat.position, gps);
    if (numero === recherche.current) {
      setEtat({ etat: 'trouvee', position: precisee });
    }
  }, []);

  // Au premier affichage de l'accueil : déjà autorisée → on cherche ; jamais demandée → phrase d'explication d'abord.
  useEffect(() => {
    Location.getForegroundPermissionsAsync()
      .then((permission) => {
        if (permission.granted) {
          chercher();
        } else {
          setEtat({ etat: permission.canAskAgain ? 'a_expliquer' : 'refusee' });
        }
      })
      .catch(() => setEtat({ etat: 'refusee' }));
  }, [chercher]);

  /** Bouton « Continuer » (Apple 5.1.1(iv) : pas « Autoriser » avant la demande système) : la demande n'est faite qu'à ce moment. */
  const autoriser = useCallback(async () => {
    const permission = await Location.requestForegroundPermissionsAsync().catch(() => null);
    if (permission?.granted) {
      await chercher();
    } else {
      setEtat({ etat: 'refusee' });
    }
  }, [chercher]);

  return { ...etat, autoriser, actualiser: chercher };
}
