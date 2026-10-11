import Constants from 'expo-constants';

/**
 * Adresse de l'API (docs/API.md). En développement : l'API locale servie par Herd sur le Mac
 * (https://spettacoli.test, joignable depuis le simulateur). Réglable dans app/.env (EXPO_PUBLIC_API_URL).
 */
export const API_URL = (process.env.EXPO_PUBLIC_API_URL ?? 'https://spettacoli.test').replace(/\/$/, '');

/** Version de l'app envoyée à chaque appel (X-App-Version) : l'API refuse une version trop ancienne (426, F2.8). */
export const VERSION_APP = Constants.expoConfig?.version ?? '1.0.0';

/** Le back-office répond-il ? (route de santé de Laravel, sans en-têtes particuliers) */
export async function apiJoignable(): Promise<boolean> {
  try {
    const reponse = await fetch(`${API_URL}/up`, { headers: { Accept: 'text/html' } });

    return reponse.ok;
  } catch {
    return false;
  }
}
