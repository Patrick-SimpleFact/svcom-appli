import * as AppleAuthentication from 'expo-apple-authentication';
import { GoogleSignin, isSuccessResponse, statusCodes } from '@react-native-google-signin/google-signin';
import { Platform } from 'react-native';

/**
 * « Continuer avec Apple » et « Continuer avec Google » (F1.4, M10b) : le téléphone fournit un jeton d'identité signé,
 * que le BO vérifie (API §7). Rien n'est stocké ici ; le jeton de l'app vient ensuite du BO.
 */

/** Identifiants OAuth du projet Google Cloud « Spettacolli » (publics, pas des secrets). */
export const GOOGLE_CLIENT_WEB = '42633763194-3ul902ahglgi7i1scrobglg26fhvfdib.apps.googleusercontent.com';
export const GOOGLE_CLIENT_IOS = '42633763194-br6hme9vk7ub3uuqodlpdeusv3fubeh4.apps.googleusercontent.com';

export type JetonExterne = {
  jeton: string;
  /** Apple seulement : code d'autorisation, échangé par le BO pour révoquer l'accès à la suppression du compte. */
  code_autorisation?: string;
  /** Apple ne donne le prénom qu'à la toute première connexion : on le propose dans « Faisons connaissance ». */
  prenom?: string;
};

/** Apple n'est proposé que sur iPhone (obligatoire là-bas dès qu'il y a Google, règle 4.8 de l'App Store). */
export async function appleDisponible(): Promise<boolean> {
  return Platform.OS === 'ios' && (await AppleAuthentication.isAvailableAsync().catch(() => false));
}

/** Jeton Apple, ou `null` si l'utilisateur a annulé. */
export async function jetonApple(): Promise<JetonExterne | null> {
  try {
    const r = await AppleAuthentication.signInAsync({
      requestedScopes: [AppleAuthentication.AppleAuthenticationScope.FULL_NAME, AppleAuthentication.AppleAuthenticationScope.EMAIL],
    });
    if (!r.identityToken) {
      throw new Error('Apple : pas de jeton d’identité');
    }
    return { jeton: r.identityToken, code_autorisation: r.authorizationCode ?? undefined, prenom: r.fullName?.givenName ?? undefined };
  } catch (e) {
    if ((e as { code?: string }).code === 'ERR_REQUEST_CANCELED') {
      return null;
    }
    throw e;
  }
}

let googlePret = false;

/** Jeton Google, ou `null` si l'utilisateur a annulé. Destinataire du jeton : l'identifiant « Web » du BO. */
export async function jetonGoogle(): Promise<JetonExterne | null> {
  if (!googlePret) {
    GoogleSignin.configure({ webClientId: GOOGLE_CLIENT_WEB, iosClientId: GOOGLE_CLIENT_IOS });
    googlePret = true;
  }
  try {
    if (Platform.OS === 'android') {
      await GoogleSignin.hasPlayServices({ showPlayServicesUpdateDialog: true });
    }
    const r = await GoogleSignin.signIn();
    if (!isSuccessResponse(r)) {
      return null;
    }
    if (!r.data.idToken) {
      throw new Error('Google : pas de jeton d’identité');
    }
    return { jeton: r.data.idToken, prenom: r.data.user.givenName ?? undefined };
  } catch (e) {
    const code = (e as { code?: string }).code;
    if (code === statusCodes.SIGN_IN_CANCELLED || code === statusCodes.IN_PROGRESS) {
      return null;
    }
    throw e;
  }
}

/** À la déconnexion : le prochain « Continuer avec Google » laisse choisir un autre compte. */
export function oublierGoogle(): void {
  if (googlePret) {
    GoogleSignin.signOut().catch(() => {});
  }
}
