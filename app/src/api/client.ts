import Constants from 'expo-constants';
import { Platform } from 'react-native';

import { identifiantAppareil } from '@/api/appareil';

/**
 * Adresse de l'API (docs/API.md). En développement : l'API locale servie par Herd sur le Mac
 * (https://spettacoli.test, joignable depuis le simulateur). Réglable dans app/.env (EXPO_PUBLIC_API_URL).
 * L'émulateur Android ne connaît ni spettacoli.test ni le certificat de Herd : il a sa propre adresse
 * (EXPO_PUBLIC_API_URL_ANDROID, voir README).
 */
const URL_ANDROID = Platform.OS === 'android' ? process.env.EXPO_PUBLIC_API_URL_ANDROID : undefined;
export const API_URL = (URL_ANDROID ?? process.env.EXPO_PUBLIC_API_URL ?? 'https://spettacoli.test').replace(/\/$/, '');

/** Version de l'app envoyée à chaque appel (X-App-Version) : l'API refuse une version trop ancienne (426, F2.8). */
export const VERSION_APP = Constants.expoConfig?.version ?? '1.0.0';

/** Numéro de build de cette installation (iOS : buildNumber, Android : versionCode), figé dans l'app à la compilation. */
export const BUILD_APP = Number(Platform.OS === 'android' ? Constants.expoConfig?.android?.versionCode : Constants.expoConfig?.ios?.buildNumber) || 0;

/** Erreur renvoyée par l'API (API §1) : seul le `code` sert à l'app, le message technique n'est jamais affiché (F2.8). */
export class ErreurApi extends Error {
  constructor(
    public readonly code: string,
    public readonly statut: number,
  ) {
    super(`API : ${code} (${statut})`);
  }
}

/** Jeton du compte connecté (API §7, Laravel Sanctum) ; `null` en mode invité. */
let jeton: string | null = null;

export function definirJeton(valeur: string | null) {
  jeton = valeur;
}

/** Prévenu quand l'API refuse le jeton (401) : l'app repasse en mode invité. */
let surJetonRefuse: (() => void) | null = null;

export function quandJetonRefuse(rappel: (() => void) | null) {
  surJetonRefuse = rappel;
}

/** Prévenu quand l'API répond 426 : l'app affiche alors l'écran « mettez à jour » (F2.8). */
let surVersionObsolete: (() => void) | null = null;

export function quandVersionObsolete(rappel: (() => void) | null) {
  surVersionObsolete = rappel;
}

type Parametres = Record<string, string | number | boolean | number[] | null | undefined>;

/** Paramètres d'URL ; une liste devient `cle[]=a&cle[]=b` (format attendu par Laravel), les valeurs vides sont omises. */
export function chaineParametres(parametres: Parametres = {}): string {
  const morceaux = Object.entries(parametres).flatMap(([cle, valeur]) => {
    if (valeur === null || valeur === undefined) {
      return [];
    }
    const valeurs = Array.isArray(valeur) ? valeur.map((v) => [`${cle}[]`, v] as const) : [[cle, valeur] as const];

    return valeurs.map(([c, v]) => `${encodeURIComponent(c)}=${encodeURIComponent(String(v))}`);
  });

  return morceaux.length ? `?${morceaux.join('&')}` : '';
}

/** Appel de l'API avec les en-têtes communs (X-Appareil, X-App-Version) ; renvoie le JSON ou lève une ErreurApi. */
export async function appelApi<T>(chemin: string, options: { methode?: string; corps?: unknown; parametres?: Parametres } = {}): Promise<T> {
  const reponse = await fetch(`${API_URL}${chemin}${chaineParametres(options.parametres)}`, {
    method: options.methode ?? 'GET',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Appareil': await identifiantAppareil(),
      'X-App-Version': VERSION_APP,
      ...(jeton ? { Authorization: `Bearer ${jeton}` } : {}),
    },
    body: options.corps === undefined ? undefined : JSON.stringify(options.corps),
  });

  if (reponse.status === 204) {
    return undefined as T;
  }
  if (reponse.ok) {
    return (await reponse.json()) as T;
  }

  const code = await reponse
    .json()
    .then((json: { erreur?: { code?: string } }) => json.erreur?.code)
    .catch(() => undefined);

  if (reponse.status === 426) {
    surVersionObsolete?.();
  }
  if (reponse.status === 401 && jeton) {
    surJetonRefuse?.();
  }

  throw new ErreurApi(code ?? 'erreur_interne', reponse.status);
}

/** Compare deux versions « 1.2.3 » : négatif si a < b, 0 si égales, positif si a > b. */
export function comparerVersions(a: string, b: string): number {
  const pa = a.split('.').map(Number);
  const pb = b.split('.').map(Number);

  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const ecart = (pa[i] || 0) - (pb[i] || 0);
    if (ecart !== 0) {
      return ecart;
    }
  }

  return 0;
}
