/// <reference types="jest" />
import { ajouterRecente, chercher, jourTalon, MAX_RECENTES, type Recente, weekEnd } from '@/recherche/recherche';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

describe('recherche (F4)', () => {
  it('garde 10 recherches récentes, la dernière en tête, sans doublon', () => {
    const texte = (libelle: string): Recente => ({ type: 'texte', libelle, criteres: { q: libelle, libelle } });
    let liste: Recente[] = [];
    for (let i = 0; i < 12; i++) {
      liste = ajouterRecente(liste, texte(`recherche ${i}`));
    }
    expect(liste).toHaveLength(MAX_RECENTES);
    expect(liste[0].libelle).toBe('recherche 11');

    liste = ajouterRecente(liste, texte('RECHERCHE 5'));
    expect(liste[0].libelle).toBe('RECHERCHE 5');
    expect(liste.filter((r) => r.libelle.toLowerCase() === 'recherche 5')).toHaveLength(1);
    // un lieu et une recherche de même nom ne se confondent pas
    expect(ajouterRecente(liste, { type: 'lieu', libelle: 'RECHERCHE 5', id: 3 }).slice(0, 2).map((r) => r.type)).toEqual(['lieu', 'texte']);
  });

  it('donne le week-end en cours ou le prochain', () => {
    expect(weekEnd(new Date(2026, 9, 14))).toEqual({ du: '2026-10-16', au: '2026-10-18' }); // mercredi
    expect(weekEnd(new Date(2026, 9, 17))).toEqual({ du: '2026-10-16', au: '2026-10-18' }); // samedi : celui en cours
    expect(weekEnd(new Date(2026, 9, 18))).toEqual({ du: '2026-10-16', au: '2026-10-18' }); // dimanche
  });

  it('met le jour dans le talon', () => {
    expect(jourTalon('2026-10-17')).toBe('sam. 17');
  });

  it('cherche dans toute la France sans lieu, autour d’une ville sinon', async () => {
    globalThis.fetch = jest.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) } as Response));
    await chercher({ q: 'laurette', libelle: 'laurette' });
    await chercher({ ville: { id: 12329, nom: 'Bordeaux' }, genres: [5], du: '2026-10-16', au: '2026-10-18', libelle: 'Bordeaux' });
    const [premier, second] = (globalThis.fetch as jest.Mock).mock.calls.map((c) => c[0] as string);

    expect(premier).toMatch(/\/v1\/recherche\?q=laurette&tri=date$/);
    expect(second).toContain('/v1/recherche?ville_id=12329&genres%5B%5D=5&du=2026-10-16&au=2026-10-18&tri=date');
  });
});
