import * as Location from 'expo-location';
import { useCallback, useEffect, useState } from 'react';

/** `approximative` : le GPS n'a pas répondu à temps, on part de la dernière position connue (F2.8). */
export type Position = { lat: number; lon: number; libelle: string | null; pays: string | null; approximative: boolean };

/**
 * Où cherche-t-on ? (F2.1) — `a_expliquer` : la phrase d'explication s'affiche avant la demande système ;
 * `refusee` : localisation refusée ou indisponible (écran « Où êtes-vous ? »).
 */
export type EtatPosition = { etat: 'verification' | 'a_expliquer' | 'recherche' | 'refusee' } | { etat: 'trouvee'; position: Position };

/** Délai au-delà duquel on se contente de la dernière position connue (F2.8 : localisation lente). */
const DELAI_GPS_MS = 5000;

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

async function localiser(): Promise<Position | null> {
  const point = await coordonnees();
  if (!point) {
    return null;
  }

  const [adresse] = await Location.reverseGeocodeAsync({ latitude: point.lat, longitude: point.lon }).catch(() => []);

  return { ...point, libelle: libelleLieu(adresse), pays: adresse?.isoCountryCode ?? null };
}

export function usePosition() {
  const [etat, setEtat] = useState<EtatPosition>({ etat: 'verification' });

  const chercher = useCallback(async () => {
    setEtat({ etat: 'recherche' });
    const position = await localiser();
    setEtat(position ? { etat: 'trouvee', position } : { etat: 'refusee' });
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

  /** Bouton « Autoriser la localisation » : la demande système n'est faite qu'à ce moment. */
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
