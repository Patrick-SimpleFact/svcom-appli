/// <reference types="jest" />
import { act, render } from '@testing-library/react-native';
import * as Location from 'expo-location';

import { ECART_SANS_RECHARGEMENT_M, positionPrecisee, usePosition } from '@/autour/position';

jest.mock('expo-location', () => ({
  Accuracy: { Balanced: 3 },
  getForegroundPermissionsAsync: jest.fn(),
  getCurrentPositionAsync: jest.fn(),
  getLastKnownPositionAsync: jest.fn(),
  reverseGeocodeAsync: jest.fn(),
}));
jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

const mock = Location as jest.Mocked<typeof Location>;
const coords = (lat: number, lon: number) => ({ coords: { latitude: lat, longitude: lon } }) as Location.LocationObject;
const avignon = { lat: 43.9493, lon: 4.8055, libelle: 'Avignon', pays: 'FR', approximative: true };

describe('position précisée par un GPS tardif (« Position approximative » qui restait affiché, 29/10/2026)', () => {
  it('tout près : garde le point (pas de rechargement) et retire le bandeau', async () => {
    const nommer = jest.fn();
    // ≈ 110 m plus au nord
    expect(await positionPrecisee(avignon, { lat: 43.9503, lon: 4.8055 }, nommer)).toEqual({ ...avignon, approximative: false });
    expect(nommer).not.toHaveBeenCalled();
    expect(ECART_SANS_RECHARGEMENT_M).toBe(300);
  });

  it('plus loin : remplace le point et renomme la commune', async () => {
    const nommer = jest.fn(() => Promise.resolve({ libelle: 'Villeneuve-lès-Avignon', pays: 'FR' }));
    expect(await positionPrecisee(avignon, { lat: 43.9668, lon: 4.7959 }, nommer)).toEqual({ lat: 43.9668, lon: 4.7959, libelle: 'Villeneuve-lès-Avignon', pays: 'FR', approximative: false });
  });
});

describe('accueil : GPS qui répond après 5 s', () => {
  let etat: ReturnType<typeof usePosition>;
  function Sonde() {
    etat = usePosition();

    return null;
  }

  beforeEach(() => {
    jest.useFakeTimers();
    mock.getForegroundPermissionsAsync.mockResolvedValue({ granted: true, canAskAgain: true } as Location.LocationPermissionResponse);
    mock.reverseGeocodeAsync.mockResolvedValue([{ city: 'Avignon', district: null, subregion: 'Vaucluse', isoCountryCode: 'FR' } as Location.LocationGeocodedAddress]);
    mock.getLastKnownPositionAsync.mockResolvedValue(coords(43.9493, 4.8055));
  });
  afterEach(() => jest.useRealTimers());

  it('affiche d’abord la dernière position connue (approximative), puis retire le bandeau quand le GPS arrive', async () => {
    mock.getCurrentPositionAsync.mockImplementation(() => new Promise((ok) => setTimeout(() => ok(coords(43.9495, 4.8056)), 8000)));
    await render(<Sonde />);

    await act(async () => jest.advanceTimersByTimeAsync(5000));
    expect(etat).toMatchObject({ etat: 'trouvee', position: { lat: 43.9493, libelle: 'Avignon', approximative: true } });

    await act(async () => jest.advanceTimersByTimeAsync(3000));
    expect(etat).toMatchObject({ etat: 'trouvee', position: { lat: 43.9493, libelle: 'Avignon', approximative: false } });
  });

  it('garde le bandeau si le GPS ne répond pas dans les 30 s', async () => {
    mock.getCurrentPositionAsync.mockImplementation(() => new Promise(() => {}));
    await render(<Sonde />);

    await act(async () => jest.advanceTimersByTimeAsync(31000));
    expect(etat).toMatchObject({ etat: 'trouvee', position: { approximative: true } });
  });
});
