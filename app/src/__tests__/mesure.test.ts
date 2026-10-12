/// <reference types="jest" />
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Network from 'expo-network';

import {
  _fileMesure, _reinitialiserMesure, accueilAffiche, carteCliquee, definirLieuMesure, encoreValables, envoyerLot, filtresModifies, MAX_PAR_LOT, mesurer,
} from '@/mesure/mesure';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));
jest.mock('expo-network', () => ({ getNetworkStateAsync: jest.fn() }));

const enLigne = (oui: boolean) => (Network.getNetworkStateAsync as jest.Mock).mockResolvedValue({ isConnected: oui, isInternetReachable: oui });
const reponse = (statut: number, json: unknown = {}) => Promise.resolve({ ok: statut < 300, status: statut, json: () => Promise.resolve(json) } as Response);
const corps = (appel = 0) => JSON.parse((globalThis.fetch as jest.Mock).mock.calls[appel][1].body);

beforeEach(async () => {
  await AsyncStorage.clear();
  _reinitialiserMesure();
  enLigne(true);
  globalThis.fetch = jest.fn(() => reponse(202, { recus: 1, ignores: 0 }));
});

describe('statistiques d’usage par lots (M15, API §11)', () => {
  it('envoie les événements notés, avec la ville choisie, puis vide la file', async () => {
    definirLieuMesure({ ville_id: 42 });
    mesurer('fiche_vue', { spectacle_id: 812, origine: 'liste_ce_soir' });
    mesurer('vue_carte');

    await envoyerLot();

    const lot = corps();
    expect(String((globalThis.fetch as jest.Mock).mock.calls[0][0])).toMatch(/\/v1\/evenements$/);
    expect(lot.lat).toBeUndefined();
    expect(lot.evenements.map((e: { type: string }) => e.type)).toEqual(['fiche_vue', 'vue_carte']);
    expect(lot.evenements[0]).toMatchObject({ ville_id: 42, donnees: { spectacle_id: 812, origine: 'liste_ce_soir' } });
    expect(_fileMesure()).toEqual([]);
  });

  it('n’envoie que la position arrondie à ≈ 1 km, au niveau du lot', async () => {
    definirLieuMesure({ lat: 43.94936, lon: 4.80551 });
    mesurer('ouverture');

    await envoyerLot();

    expect(corps()).toMatchObject({ lat: 43.95, lon: 4.81 });
    expect(corps().evenements[0].ville_id).toBeUndefined();
  });

  it('garde le lot hors ligne ou si le BO ne répond pas, l’abandonne s’il est refusé', async () => {
    mesurer('ouverture');

    enLigne(false);
    await envoyerLot();
    expect(globalThis.fetch).not.toHaveBeenCalled();

    enLigne(true);
    globalThis.fetch = jest.fn(() => Promise.reject(new Error('réseau')));
    await envoyerLot();
    expect(_fileMesure()).toHaveLength(1);

    globalThis.fetch = jest.fn(() => reponse(422));
    await envoyerLot();
    expect(_fileMesure()).toEqual([]);
  });

  it('envoie 200 événements au plus par lot', async () => {
    for (let i = 0; i < MAX_PAR_LOT + 5; i++) {
      mesurer('fiche_vue');
    }

    await envoyerLot();
    expect(corps().evenements).toHaveLength(MAX_PAR_LOT);
    expect(_fileMesure()).toHaveLength(5);
  });

  it('retrouve les événements restés sur le téléphone après une fermeture hors ligne', async () => {
    await AsyncStorage.setItem('spettacoli.evenements_en_attente', JSON.stringify([{ type: 'ouverture', horodatage: new Date().toISOString() }]));
    mesurer('accueil_affiche');

    await envoyerLot();
    expect(corps().evenements.map((e: { type: string }) => e.type)).toEqual(['ouverture', 'accueil_affiche']);
  });

  it('oublie les événements de plus de 7 jours (le BO les ignorerait)', () => {
    const maintenant = Date.parse('2026-10-12T20:00:00Z');
    const e = (h: string) => ({ type: 'ouverture', horodatage: h });

    expect(encoreValables([e('2026-10-04T20:00:00Z'), e('2026-10-06T20:00:00Z')], maintenant)).toEqual([e('2026-10-06T20:00:00Z')]);
  });

  it('mesure le premier clic sur une carte une seule fois par ouverture (KPI < 30 s)', () => {
    jest.useFakeTimers({ now: Date.parse('2026-10-12T20:00:00Z') });
    accueilAffiche();
    accueilAffiche();
    jest.setSystemTime(Date.parse('2026-10-12T20:00:12.34Z'));
    carteCliquee();
    carteCliquee();
    jest.useRealTimers();

    const file = _fileMesure();
    expect(file.map((e) => e.type)).toEqual(['accueil_affiche', 'premier_clic_carte']);
    expect(file[1].donnees).toEqual({ secondes: 12.3 });
  });

  it('repère les filtres de la recherche qui ont changé', () => {
    expect(filtresModifies({ genres: [1], quand: 'ce_soir', prix_max: undefined }, { genres: [1, 2], quand: 'ce_soir', prix_max: 20 }, ['genres', 'quand', 'prix_max'])).toEqual([
      'genres',
      'prix_max',
    ]);
  });
});
