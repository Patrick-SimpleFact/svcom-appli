import * as Notifications from 'expo-notifications';
import { Alert, Platform } from 'react-native';

import { appelApi } from '@/api/client';

/**
 * Notifications (F3.4, M12b) : le BO les envoie lui-même à Apple et Google, à 18 h, une par jour au plus.
 * L'app demande l'autorisation au premier suivi (jamais avant), transmet le jeton natif du téléphone
 * (`getDevicePushTokenAsync`, pas de service Expo) et ouvre les nouveautés quand on touche une notification.
 */

/** Canal Android des alertes (Android 8+ : obligatoire ; Android 13+ : la demande d'autorisation suit sa création). */
const CANAL = 'alertes';

/** Écran ouvert au toucher (`donnees.ecran` envoyé par le BO). */
export type Ouverture = { ecran: string; notificationId: number | null };

/** Notification reçue app ouverte : bannière et son, comme app fermée. */
export function configurerAffichage(): void {
  Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: true }),
  });
}

async function preparerCanal(): Promise<void> {
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync(CANAL, {
      name: 'Alertes de vos suivis',
      description: 'Nouveaux spectacles des lieux et artistes que vous suivez, une fois par jour à 18 h.',
      importance: Notifications.AndroidImportance.DEFAULT,
      lightColor: '#D23A1F',
    });
  }
}

/** « Pas encore demandé », « accordé » ou « refusé » (réglages du téléphone). */
export async function etatAutorisation(): Promise<'a_demander' | 'accordee' | 'refusee'> {
  const { status, canAskAgain } = await Notifications.getPermissionsAsync();
  if (status === 'granted') {
    return 'accordee';
  }
  return status === 'undetermined' || canAskAgain ? 'a_demander' : 'refusee';
}

/** Demande système (une seule fois en pratique : iOS ne redemande jamais après un refus). */
export async function demanderAutorisation(): Promise<boolean> {
  await preparerCanal();
  const { status } = await Notifications.requestPermissionsAsync({ ios: { allowAlert: true, allowBadge: true, allowSound: true } });
  return status === 'granted';
}

/** Jeton natif du téléphone (APNs sur iPhone, FCM sur Android), ou `null` sans autorisation ou hors appareil réel. */
export async function jetonNatif(): Promise<string | null> {
  if ((await etatAutorisation()) !== 'accordee') {
    return null;
  }
  try {
    await preparerCanal();
    const { data } = await Notifications.getDevicePushTokenAsync();
    return typeof data === 'string' && data.length > 0 ? data : null;
  } catch {
    // Simulateur iOS sans service de notifications, Android sans services Google : l'app continue sans.
    return null;
  }
}

/** Le BO apprend le jeton avec la fiche de l'appareil (`POST /v1/appareils`, API §2). */
export async function transmettreJeton(): Promise<void> {
  const jeton = await jetonNatif();
  if (jeton) {
    await appelApi('/v1/appareils', { methode: 'POST', corps: { plateforme: Platform.OS === 'android' ? 'android' : 'ios', jeton_push: jeton } }).catch(() => {});
  }
}

/**
 * Contenu utile d'une notification touchée. Le BO met ses données à la racine de la notification (APNs) ou dans
 * `data` (FCM) : Expo les range dans `trigger.payload` (iPhone) ou `trigger.remoteMessage.data` (Android),
 * et seulement parfois dans `content.data`. On regarde aux trois endroits.
 */
export function lireOuverture(reponse: Notifications.NotificationResponse | null): Ouverture | null {
  const requete = reponse?.notification.request;
  const declencheur = requete?.trigger as { payload?: Record<string, unknown>; remoteMessage?: { data?: Record<string, unknown> } } | null | undefined;
  const donnees: Record<string, unknown> = {
    ...(declencheur?.remoteMessage?.data ?? {}),
    ...(declencheur?.payload ?? {}),
    ...((requete?.content.data as Record<string, unknown> | null | undefined) ?? {}),
  };
  if (typeof donnees.ecran !== 'string') {
    return null;
  }
  const id = Number(donnees.notification_id);
  return { ecran: donnees.ecran, notificationId: Number.isInteger(id) && id > 0 ? id : null };
}

/** Taux d'ouverture (F7, statistiques du BO). */
export function signalerOuverture(ouverture: Ouverture): void {
  if (ouverture.notificationId) {
    appelApi(`/v1/notifications/${ouverture.notificationId}/ouverte`, { methode: 'POST' }).catch(() => {});
  }
}

/**
 * Au premier suivi (F3.4) : on explique d'abord, puis le téléphone demande. Un seul bouton « Continuer » :
 * Apple (5.1.1(iv)) refuse un « Autoriser » ou un « Plus tard » avant la demande système ; le refus se fait dans celle-ci.
 */
export async function proposerAlertes(type: 'lieu' | 'artiste'): Promise<void> {
  if ((await etatAutorisation()) !== 'a_demander') {
    return;
  }
  const raison = type === 'lieu'
    ? 'Pour vous prévenir quand ce lieu annonce un spectacle.'
    : 'Pour vous prévenir quand cet artiste a une nouvelle date près de chez vous.';
  await new Promise<void>((resolve) =>
    Alert.alert('Être prévenu ?', `${raison} Une notification par jour au plus, à 18 h.`, [
      { text: 'Continuer', onPress: () => resolve() },
    ], { cancelable: false }),
  );
  if (await demanderAutorisation()) {
    await transmettreJeton();
  }
}

/** Pastille de l'icône de l'app = nouveautés non vues (0 : pastille effacée), comme le badge de l'onglet Favoris. */
export function mettreAJourPastille(nombre: number): void {
  Notifications.setBadgeCountAsync(Math.max(0, nombre)).catch(() => {});
}
