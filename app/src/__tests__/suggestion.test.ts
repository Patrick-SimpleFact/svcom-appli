/// <reference types="jest" />
import AsyncStorage from '@react-native-async-storage/async-storage';

import { chargerSuggestion, choisir, libelleTarifs, lireChoix } from '@/suggestion/suggestion';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

const reponse = (statut: number, json: unknown = {}, attente = 0) =>
  new Promise<Response>((fin) => setTimeout(() => fin({ ok: statut < 300, status: statut, json: () => Promise.resolve(json) } as Response), attente));

beforeEach(async () => {
  await AsyncStorage.clear();
});

describe('suggestion à l’ouverture (F6)', () => {
  it('ne montre rien tant que l’utilisateur n’a pas répondu, puis garde son choix et l’envoie au BO', async () => {
    globalThis.fetch = jest.fn(() => reponse(200));
    expect(await lireChoix()).toBeNull();

    await choisir('oui');
    expect(await lireChoix()).toBe('oui');
    const [url, options] = (globalThis.fetch as jest.Mock).mock.calls[0];
    expect(String(url)).toMatch(/\/v1\/appareils\/suggestion$/);
    expect(JSON.parse(options.body)).toEqual({ choix: 'oui' });
  });

  it('renonce si le BO n’a rien à proposer (204) ou met plus d’une seconde', async () => {
    globalThis.fetch = jest.fn(() => reponse(204));
    expect(await chargerSuggestion({ lat: 43.9, lon: 4.8 }, [1])).toBeNull();

    globalThis.fetch = jest.fn(() => reponse(200, { affichage_id: 1 }, 200));
    expect(await chargerSuggestion({ lat: 43.9, lon: 4.8 }, [1], 50)).toBeNull();
    expect(await chargerSuggestion({ lat: 43.9, lon: 4.8 }, [1], 500)).toEqual({ affichage_id: 1 });
  });

  it('affiche les tarifs sans jamais les inventer', () => {
    expect(libelleTarifs({ prix_min: 6, prix_max: 18, gratuit: false })).toBe('6–18 €');
    expect(libelleTarifs({ prix_min: 12.5, prix_max: null, gratuit: false })).toBe('12,50 €');
    expect(libelleTarifs({ prix_min: null, prix_max: null, gratuit: true })).toBe('Gratuit');
    expect(libelleTarifs({ prix_min: null, prix_max: null, gratuit: false })).toBeNull();
  });
});
