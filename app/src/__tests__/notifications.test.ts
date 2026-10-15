import { lireOuverture } from '@/notifications/notifications';

/** M12b : contenu d'une notification touchée (envoyée par le BO à 18 h, F3.4). */
const reponse = (data: Record<string, unknown> | undefined) =>
  ({ notification: { request: { identifier: 'n1', content: { data } } } }) as unknown as Parameters<typeof lireOuverture>[0];

const brute = (trigger: Record<string, unknown>) =>
  ({ notification: { request: { identifier: 'n2', content: { data: {} }, trigger } } }) as unknown as Parameters<typeof lireOuverture>[0];

describe('lireOuverture', () => {
  it('lit les données mises à la racine par le BO : iPhone (payload APNs) et Android (data FCM)', () => {
    expect(lireOuverture(brute({ type: 'push', payload: { aps: { alert: 'x' }, ecran: 'nouveautes', notification_id: '7' } }))).toEqual({ ecran: 'nouveautes', notificationId: 7 });
    expect(lireOuverture(brute({ type: 'push', remoteMessage: { data: { ecran: 'nouveautes', notification_id: '8' } } }))).toEqual({ ecran: 'nouveautes', notificationId: 8 });
  });

  it('lit l’écran à ouvrir et l’identifiant de la notification', () => {
    expect(lireOuverture(reponse({ ecran: 'nouveautes', notification_id: '42' }))).toEqual({ ecran: 'nouveautes', notificationId: 42 });
  });

  it('accepte une notification sans identifiant (test du back-office) et ignore les autres', () => {
    expect(lireOuverture(reponse({ ecran: 'nouveautes' }))).toEqual({ ecran: 'nouveautes', notificationId: null });
    expect(lireOuverture(reponse({ autre: 1 }))).toBeNull();
    expect(lireOuverture(reponse(undefined))).toBeNull();
    expect(lireOuverture(null)).toBeNull();
  });
});
