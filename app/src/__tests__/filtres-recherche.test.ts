/// <reference types="jest" />
import { chercher, type Criteres, joursDe, libelleQuand, puces, retirer, toutEffacer } from '@/recherche/recherche';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

const genres = (id: number) => ({ 1: 'Théâtre', 2: 'Humour' })[id] ?? '';
const complet: Criteres = {
  q: 'molière', ville: { id: 1, nom: 'Avignon' }, rayon: 10000, genres: [1, 2], quand: 'date', du: '2026-10-17', au: '2026-10-17',
  moment: 'soiree', prix_max: 15, masquer_complets: true, tri: 'prix', libelle: 'molière',
};

describe('filtres de la recherche (F4.3)', () => {
  it('affiche les filtres actifs en puces, comme la maquette', () => {
    expect(puces(complet, genres).map((p) => p.libelle)).toEqual([
      'Avignon · 10 km', 'Samedi 17 octobre', 'Théâtre, Humour', 'Soirée', 'Moins de 15 €', 'Sans les complets',
    ]);
    expect(puces({ libelle: 'x', prix_max: 0, jeune_public: true }, genres).map((p) => p.libelle)).toEqual(['Jeune public', 'Gratuit']);
  });

  it('retire un filtre sans toucher au texte ni au tri ; « Tout effacer » ne garde qu’eux', () => {
    const sansOu = retirer(complet, 'ou');
    expect(sansOu.ville).toBeUndefined();
    expect(sansOu.rayon).toBeUndefined();
    expect(sansOu.q).toBe('molière');
    expect(retirer(complet, 'quand')).not.toHaveProperty('du');
    expect(toutEffacer(complet)).toEqual({ q: 'molière', tri: 'prix', libelle: 'molière' });
  });

  it('calcule les jours de chaque choix « Quand ? »', () => {
    const mercredi = new Date(2026, 9, 14);
    expect(joursDe('aujourdhui', mercredi)).toEqual({ du: '2026-10-14', au: '2026-10-14' });
    expect(joursDe('demain', mercredi)).toEqual({ du: '2026-10-15', au: '2026-10-15' });
    expect(joursDe('periode', mercredi, { du: '2026-10-20', au: '2026-10-25' })).toEqual({ du: '2026-10-20', au: '2026-10-25' });
    expect(libelleQuand({ libelle: 'x', quand: 'periode', du: '2026-10-20', au: '2026-11-02' })).toBe('Du 20 oct. au 2 nov.');
  });

  it('envoie les filtres à l’API ; « Gratuit » = gratuit=1, le rayon seulement avec un lieu', async () => {
    globalThis.fetch = jest.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) } as Response));
    await chercher(complet);
    await chercher({ libelle: 'x', prix_max: 0, rayon: 5000 });
    await chercher({ libelle: 'x', position: { lat: 43.9, lon: 4.8 }, ville: { id: 1, nom: 'Avignon' }, rayon: 5000 });
    const [a, b, c] = (globalThis.fetch as jest.Mock).mock.calls.map((x) => x[0] as string);

    expect(a).toContain('q=moli%C3%A8re&ville_id=1&rayon=10000&genres%5B%5D=1&genres%5B%5D=2&du=2026-10-17&au=2026-10-17&moment=soiree&prix_max=15&masquer_complets=1&tri=prix');
    expect(b).toMatch(/\?gratuit=1&tri=date$/);
    expect(c).toContain('?lat=43.9&lon=4.8&rayon=5000'); // autour de moi l'emporte sur la ville
  });
});
