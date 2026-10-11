/// <reference types="jest" />
import { envoyerPiste, normaliserLien, signaler } from '@/contributions/contributions';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

describe('pistes et signalements (F8, F5.6)', () => {
  it('accepte une adresse web, complète « https:// », refuse le reste', () => {
    expect(normaliserLien('www.theatre-des-halles.fr')).toBe('https://www.theatre-des-halles.fr');
    expect(normaliserLien('http://billets.example.com/agenda')).toBe('http://billets.example.com/agenda');
    expect(normaliserLien('https://')).toBeNull();
    expect(normaliserLien('')).toBeNull();
    expect(normaliserLien('pas un lien')).toBeNull();
  });

  it('envoie la piste et le signalement au format de l’API', async () => {
    globalThis.fetch = jest.fn(() => Promise.resolve({ ok: true, status: 201, json: () => Promise.resolve({}) } as Response));
    await envoyerPiste({ type: 'salle', ville_id: 7, nom: 'ForumSirius', lien: 'https://forumsirius.fr', travaille_pour_le_lieu: true });
    await signaler(5397, 'horaire_faux', '  ');
    const corps = (globalThis.fetch as jest.Mock).mock.calls.map(([, o]) => JSON.parse(o.body));

    expect(corps[0]).toEqual({ type: 'salle', ville_id: 7, nom: 'ForumSirius', lien: 'https://forumsirius.fr', travaille_pour_le_lieu: true });
    expect(corps[1]).toEqual({ representation_id: 5397, motif: 'horaire_faux' }); // commentaire vide : non envoyé
  });
});
