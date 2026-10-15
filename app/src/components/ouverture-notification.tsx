import * as Notifications from 'expo-notifications';
import { router } from 'expo-router';
import { useEffect, useRef } from 'react';

import { useFavoris } from '@/favoris/favoris';
import { lireOuverture, signalerOuverture } from '@/notifications/notifications';

/**
 * Toucher une notification (F3.4) ouvre les nouveautés, app fermée ou ouverte : onglet Favoris, rechargé.
 * Rien à l'écran ; le BO compte l'ouverture.
 */
export function OuvertureNotification() {
  const reponse = Notifications.useLastNotificationResponse();
  const { recharger } = useFavoris();
  const traitee = useRef<string | null>(null);

  useEffect(() => {
    const ouverture = lireOuverture(reponse ?? null);
    const cle = reponse?.notification.request.identifier ?? null;
    if (!ouverture || !cle || traitee.current === cle) {
      return;
    }
    traitee.current = cle;
    signalerOuverture(ouverture);
    if (ouverture.ecran === 'nouveautes') {
      recharger();
      router.navigate('/favoris');
    }
  }, [reponse, recharger]);

  return null;
}
