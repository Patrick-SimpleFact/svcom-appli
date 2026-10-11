/// <reference types="jest" />
import { bornes, chargerReperes, regionAutour, zoneDeplacee } from '@/autour/autour';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

describe('vue carte (F2.6)', () => {
  const avignon = regionAutour({ lat: 43.9493, lon: 4.8055 }, 5000);

  it('cadre la carte sur le rayon retenu par la liste', () => {
    expect(avignon.latitude).toBe(43.9493);
    expect(avignon.latitudeDelta).toBeCloseTo(0.099, 2); // ≈ 11 km du nord au sud
    expect(avignon.longitudeDelta).toBeGreaterThan(avignon.latitudeDelta); // les degrés de longitude sont plus courts
    expect(bornes(avignon)).toEqual({ sud: 43.89975, nord: 43.99885, ouest: 4.73668, est: 4.87432 });
  });

  it('ne propose « Rechercher dans cette zone » qu’après un vrai déplacement', () => {
    expect(zoneDeplacee(avignon, { ...avignon, latitude: avignon.latitude + 0.01 })).toBe(false);
    expect(zoneDeplacee(avignon, { ...avignon, latitude: avignon.latitude + 0.05 })).toBe(true);
    expect(zoneDeplacee(avignon, { ...avignon, latitudeDelta: avignon.latitudeDelta * 2 })).toBe(true);
  });

  it('applique les mêmes filtres que la liste', async () => {
    globalThis.fetch = jest.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ reperes: [] }) } as Response));
    await chargerReperes(avignon, { quand: 'week_end', onglet: 'pour_vous', gouts: [1, 2] });
    const adresse = (globalThis.fetch as jest.Mock).mock.calls[0][0] as string;

    expect(adresse).toContain('/v1/lieux/carte?sud=43.89975&nord=43.99885&ouest=4.73668&est=4.87432&quand=week_end');
    expect(adresse).toContain('genres%5B%5D=1&genres%5B%5D=2');
  });
});
