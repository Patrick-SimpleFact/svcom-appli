/// <reference types="jest" />
import { act, render } from '@testing-library/react-native';

import { FavorisProvider, useFavoris } from '@/favoris/favoris';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));
jest.mock('@/compte/compte', () => ({ useCompte: () => ({ utilisateur: { id: 1 } }) }));

const favori = (id: number, spectacleId: number) => ({ id, spectacle: { id: spectacleId, titre: `Spectacle ${spectacleId}`, image_url: null }, representation: null, seance_choisie: false });
const reponses: Record<string, unknown> = {
  'GET /v1/moi/favoris': { ce_soir: [favori(10, 942)], a_venir: [], passes: [] },
  'GET /v1/moi/suivis': { suivis: [{ id: 5, type: 'lieu', cible: { id: 2584, nom: 'Pelousse Paradise', ville: 'Alès' }, nouveautes: 1 }] },
  'GET /v1/moi/nouveautes': { non_vues: 2, nouveautes: [{ id: 1, vue: false }, { id: 2, vue: false }] },
  'POST /v1/moi/suivis': { suivi: { id: 6, type: 'artiste', cible: { id: 7, nom: 'Laura Cox' }, nouveautes: 0 }, deja_suivi: false },
};

let etat: ReturnType<typeof useFavoris>;
/** Composant sonde : expose l'état du contexte au test. */
function Sonde() {
  // eslint-disable-next-line react-hooks/globals -- volontaire : le test lit l'état après chaque action.
  etat = useFavoris();

  return null;
}

beforeEach(() => {
  globalThis.fetch = jest.fn((url: unknown, o?: RequestInit) => {
    const cle = `${o?.method ?? 'GET'} ${String(url).replace(/^.*(\/v1\/[^?]*).*$/, '$1')}`;

    return Promise.resolve({ ok: true, status: o?.method === 'DELETE' ? 204 : 200, json: () => Promise.resolve(reponses[cle] ?? {}) } as Response);
  });
});

describe('favoris, suivis et nouveautés (F3)', () => {
  it('charge le compte, retire un favori, suit et ne suit plus, marque les nouveautés comme vues', async () => {
    await render(
      <FavorisProvider>
        <Sonde />
      </FavorisProvider>,
    );
    await act(async () => {});

    expect(etat.favoriDe(942)).toBe(10);
    expect(etat.nonVues).toBe(2);
    expect(etat.suivis.map((s) => s.cible.nom)).toEqual(['Pelousse Paradise']);

    await act(() => etat.retirer(10));
    expect(etat.favoriDe(942)).toBeNull();

    await act(async () => {
      await etat.suivre('artiste', 7);
    });
    expect(etat.suivis.map((s) => s.id)).toEqual([6, 5]);
    await act(() => etat.nePlusSuivre(5));
    expect(etat.suivis.map((s) => s.id)).toEqual([6]);

    await act(() => etat.marquerVues([1]));
    expect(etat.nonVues).toBe(1);

    const appels = (globalThis.fetch as jest.Mock).mock.calls.map(([u, o]) => `${o?.method ?? 'GET'} ${String(u).replace(/^.*(\/v1\/[^?]*).*$/, '$1')}`);
    expect(appels).toEqual(expect.arrayContaining(['DELETE /v1/moi/favoris/10', 'POST /v1/moi/suivis', 'DELETE /v1/moi/suivis/5', 'POST /v1/moi/nouveautes/vues']));
  });
});
