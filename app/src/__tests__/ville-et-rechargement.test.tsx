/// <reference types="jest" />
import { act, render } from '@testing-library/react-native';
import { AppState, type AppStateStatus } from 'react-native';

import { communeDuServeur } from '@/autour/position';
import { DELAI_RECHARGEMENT_MS, Demarrage, useDemarrage } from '@/demarrage/demarrage';

jest.mock('expo-location', () => ({}));
jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));
jest.mock('@/notifications/notifications', () => ({ jetonNatif: () => Promise.resolve(null) }));

const configuration = (messages: { id: number; type: 'info' | 'alerte'; texte: string }[]) => ({
  version_minimale: '1.0.0',
  parametres: {},
  genres: [],
  messages_service: messages,
});

let reponse: unknown;
beforeEach(() => {
  reponse = {};
  globalThis.fetch = jest.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(reponse) } as Response));
});

describe('nom de la ville par le BO (géocodage d’Android muet, 29/10/2026)', () => {
  it('demande la commune au BO et renvoie null si le BO ne répond pas', async () => {
    reponse = { commune: 'Paris' };
    expect(await communeDuServeur({ lat: 48.8322, lon: 2.2875 })).toBe('Paris');
    expect(String((globalThis.fetch as jest.Mock).mock.calls[0][0])).toMatch(/\/v1\/commune\?lat=48\.8322&lon=2\.2875$/);

    (globalThis.fetch as jest.Mock).mockImplementationOnce(() => Promise.reject(new Error('réseau')));
    expect(await communeDuServeur({ lat: 48.8322, lon: 2.2875 })).toBeNull();
  });
});

describe('configuration redemandée au retour au premier plan (message de service absent sur Android, 29/10/2026)', () => {
  let etat: ReturnType<typeof useDemarrage>;
  function Sonde() {
    etat = useDemarrage();

    return null;
  }

  it('recharge seulement après le délai, et affiche le nouveau message', async () => {
    let auChangement: (e: AppStateStatus) => void = () => {};
    jest.spyOn(AppState, 'addEventListener').mockImplementation((_type, f) => {
      auChangement = f as (e: AppStateStatus) => void;

      return { remove: jest.fn() } as never;
    });
    const maintenant = jest.spyOn(Date, 'now').mockReturnValue(1_000_000);
    const appels = () => (globalThis.fetch as jest.Mock).mock.calls.filter(([url]) => String(url).endsWith('/v1/appareils')).length;

    reponse = configuration([]);
    await render(
      <Demarrage>
        <Sonde />
      </Demarrage>,
    );
    expect(appels()).toBe(1);
    expect(etat.messagesAffiches).toEqual([]);

    // Retour au premier plan peu après : pas de nouvel appel.
    maintenant.mockReturnValue(1_000_000 + DELAI_RECHARGEMENT_MS - 1);
    await act(async () => auChangement('active'));
    expect(appels()).toBe(1);

    // Après le délai : la configuration est redemandée, le message saisi entre-temps apparaît.
    reponse = configuration([{ id: 1, type: 'alerte', texte: 'Migration ce matin' }]);
    maintenant.mockReturnValue(1_000_000 + DELAI_RECHARGEMENT_MS + 1);
    await act(async () => auChangement('background'));
    expect(appels()).toBe(1);
    await act(async () => auChangement('active'));
    expect(appels()).toBe(2);
    expect(etat.messagesAffiches.map((m) => m.texte)).toEqual(['Migration ce matin']);
  });
});
