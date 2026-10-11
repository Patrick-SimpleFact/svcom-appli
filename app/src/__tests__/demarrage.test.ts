/// <reference types="jest" />
import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('expo-crypto', () => ({ randomUUID: jest.fn(() => '0b6e1c8e-3f0a-4d7e-9b1a-2c5d8e7f6a10') }));

type Module = {
  client: typeof import('@/api/client');
  appareil: typeof import('@/api/appareil');
  demarrage: typeof import('@/demarrage/demarrage');
};

/** Modules rechargés à chaque test (l'identifiant est gardé en mémoire par le module). */
function charger(): Module {
  let modules!: Module;
  jest.isolateModules(() => {
    // require() : seul moyen de recharger un module à neuf dans jest.isolateModules.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    modules = { client: require('@/api/client'), appareil: require('@/api/appareil'), demarrage: require('@/demarrage/demarrage') };
  });

  return modules;
}

function reponse(statut: number, json: unknown) {
  return Promise.resolve({ ok: statut >= 200 && statut < 300, status: statut, json: () => Promise.resolve(json) } as Response);
}

beforeEach(async () => {
  await AsyncStorage.clear();
  globalThis.fetch = jest.fn();
});

describe('identifiant de l’appareil', () => {
  it('est généré à la première ouverture puis gardé sur le téléphone', async () => {
    const premier = await charger().appareil.identifiantAppareil();
    expect(premier).toMatch(/^[A-Za-z0-9-]{8,100}$/); // format exigé par le BO (X-Appareil)
    expect(await AsyncStorage.getItem('spettacoli.appareil')).toBe(premier);

    await AsyncStorage.setItem('spettacoli.appareil', 'appareil-deja-installe');
    expect(await charger().appareil.identifiantAppareil()).toBe('appareil-deja-installe');
  });
});

describe('appels de l’API', () => {
  it('envoie l’identifiant de l’appareil et la version de l’app', async () => {
    (globalThis.fetch as jest.Mock).mockReturnValue(reponse(200, { ok: true }));
    const { client } = charger();

    await client.appelApi('/v1/appareils', { methode: 'POST', corps: { plateforme: 'ios' } });

    const [adresse, options] = (globalThis.fetch as jest.Mock).mock.calls[0];
    expect(adresse).toBe(`${client.API_URL}/v1/appareils`);
    expect(options.method).toBe('POST');
    expect(options.headers['X-Appareil']).toMatch(/^[A-Za-z0-9-]{8,100}$/);
    expect(options.headers['X-App-Version']).toMatch(/^\d+\.\d+\.\d+$/);
    expect(JSON.parse(options.body)).toEqual({ plateforme: 'ios' });
  });

  it('prévient l’app quand la version est trop ancienne (426)', async () => {
    (globalThis.fetch as jest.Mock).mockReturnValue(reponse(426, { erreur: { code: 'version_obsolete', message: 'technique' } }));
    const { client } = charger();
    const rappel = jest.fn();
    client.quandVersionObsolete(rappel);

    await expect(client.appelApi('/v1/appareils')).rejects.toMatchObject({ code: 'version_obsolete', statut: 426 });
    expect(rappel).toHaveBeenCalledTimes(1);
  });

  it('garde seulement le code d’erreur, jamais le message technique', async () => {
    (globalThis.fetch as jest.Mock).mockReturnValue(reponse(500, 'pas du JSON'));

    await expect(charger().client.appelApi('/v1/appareils')).rejects.toMatchObject({ code: 'erreur_interne', statut: 500 });
  });
});

describe('version minimale', () => {
  it('compare les versions chiffre par chiffre', () => {
    const { comparerVersions } = charger().client;
    expect(comparerVersions('1.0.0', '1.0.0')).toBe(0);
    expect(comparerVersions('1.0.0', '1.0.1')).toBeLessThan(0);
    expect(comparerVersions('1.10.0', '1.9.0')).toBeGreaterThan(0);
    expect(comparerVersions('1.0.0', '9.0.0')).toBeLessThan(0);
  });
});

describe('messages de service', () => {
  it('n’affiche plus un message fermé par l’utilisateur', () => {
    const messages = [
      { id: 4, type: 'info' as const, texte: 'Maintenance ce soir de 23 h à minuit' },
      { id: 5, type: 'alerte' as const, texte: 'Incident en cours' },
    ];

    expect(charger().demarrage.messagesAAfficher(messages, [4]).map((m) => m.id)).toEqual([5]);
  });
});
