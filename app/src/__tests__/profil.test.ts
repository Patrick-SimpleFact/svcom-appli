/// <reference types="jest" />
import { ligneCompte, modifierPreferences, resumeGenres, supprimerMonCompte } from '@/compte/profil';
import { choisir } from '@/suggestion/suggestion';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

const noms = (id: number) => ({ 1: 'Théâtre', 2: 'Humour', 3: 'Concert', 5: 'Danse' })[id] ?? '';

describe('profil (F1.6, F1.7)', () => {
  it('résume les goûts et le compte comme la maquette', () => {
    expect(resumeGenres([1, 2], noms)).toBe('Théâtre, Humour');
    expect(resumeGenres([1, 2, 3, 5], noms)).toBe('Théâtre, Humour +2');
    expect(resumeGenres([], noms)).toBe('Aucun');
    expect(ligneCompte(['apple'], 3, 1988)).toBe('Connexion par Apple · naissance : 03/1988');
    expect(ligneCompte([], 11, 1975)).toBe('Connexion par e-mail · naissance : 11/1975');
  });

  it('envoie les réglages et la suppression au BO', async () => {
    globalThis.fetch = jest.fn((_url: unknown, o?: RequestInit) => Promise.resolve({ ok: true, status: o?.method === 'DELETE' ? 204 : 200, json: () => Promise.resolve({}) } as Response));
    await modifierPreferences({ alertes_actives: false });
    await choisir('desactive');
    await supprimerMonCompte();
    const appels = (globalThis.fetch as jest.Mock).mock.calls.map(([url, o]) => [String(url).replace(/^.*\/v1/, '/v1'), o.method, o.body]);

    expect(appels).toEqual([
      ['/v1/moi/preferences', 'PUT', JSON.stringify({ alertes_actives: false })],
      ['/v1/appareils/suggestion', 'PUT', JSON.stringify({ choix: 'desactive' })],
      ['/v1/moi', 'DELETE', undefined],
    ]);
  });
});
