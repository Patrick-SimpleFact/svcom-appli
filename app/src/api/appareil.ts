import AsyncStorage from '@react-native-async-storage/async-storage';
import { randomUUID } from 'expo-crypto';

const CLE = 'spettacoli.appareil';

let enMemoire: Promise<string> | null = null;

/**
 * Identifiant de l'appareil (en-tête X-Appareil, SCHEMA §5) : généré par l'app à l'installation et gardé sur le téléphone.
 * Ce n'est pas l'identifiant publicitaire ; il disparaît si l'app est désinstallée (pas de trousseau).
 */
export function identifiantAppareil(): Promise<string> {
  enMemoire ??= (async () => {
    const existant = await AsyncStorage.getItem(CLE);
    if (existant) {
      return existant;
    }

    const nouveau = randomUUID();
    await AsyncStorage.setItem(CLE, nouveau);

    return nouveau;
  })();

  return enMemoire;
}
