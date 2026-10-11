/// <reference types="jest" />
import { appelApi, definirJeton, ErreurApi, quandJetonRefuse } from '@/api/client';
import { messageErreur, tropJeune, verifierCode } from '@/compte/compte';

jest.mock('expo-secure-store', () => ({ getItemAsync: jest.fn(), setItemAsync: jest.fn(), deleteItemAsync: jest.fn() }));
jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

const reponse = (statut: number, json: unknown = {}) => Promise.resolve({ ok: statut < 300, status: statut, json: () => Promise.resolve(json) } as Response);

beforeEach(() => {
  definirJeton(null);
  globalThis.fetch = jest.fn(() => reponse(200));
});

describe('compte (F1.4, F1.5)', () => {
  it('réserve les comptes aux 15 ans et plus, au mois près', () => {
    const maintenant = new Date(2026, 9, 11); // octobre 2026
    expect(tropJeune(10, 2011, maintenant)).toBe(false); // 15 ans ce mois-ci
    expect(tropJeune(11, 2011, maintenant)).toBe(true); // 15 ans le mois prochain
    expect(tropJeune(1, 1980, maintenant)).toBe(false);
  });

  it('traduit les erreurs du code en phrases, jamais en message technique', () => {
    expect(messageErreur(new ErreurApi('code_invalide', 422))).toBe('Ce code n’est pas le bon. Vérifiez l’e-mail reçu.');
    expect(messageErreur(new ErreurApi('trop_d_essais', 429))).toBe('Trop d’essais. Réessayez dans 15 minutes.');
    expect(messageErreur(new TypeError('Network request failed'))).toBe('Impossible de joindre Spettacoli. Vérifiez votre connexion.');
    expect(messageErreur(new ErreurApi('erreur_interne', 500))).toBe('Une erreur est survenue. Réessayez dans un instant.');
  });

  it('reprend les goûts du mode invité à la connexion', async () => {
    await verifierCode('patrick@exemple.fr', '123456', { genres: [1, 3], rayon_m: 10000 });
    const [, options] = (globalThis.fetch as jest.Mock).mock.calls[0];
    expect(JSON.parse(options.body)).toEqual({ email: 'patrick@exemple.fr', code: '123456', gouts: [1, 3], rayon_m: 10000 });
  });

  it('envoie le jeton une fois connecté, et repasse en invité s’il est refusé', async () => {
    await appelApi('/v1/moi');
    expect((globalThis.fetch as jest.Mock).mock.calls[0][1].headers.Authorization).toBeUndefined();

    definirJeton('1|jeton-de-test');
    await appelApi('/v1/moi');
    expect((globalThis.fetch as jest.Mock).mock.calls[1][1].headers.Authorization).toBe('Bearer 1|jeton-de-test');

    const refuse = jest.fn();
    quandJetonRefuse(refuse);
    (globalThis.fetch as jest.Mock).mockReturnValueOnce(reponse(401, { erreur: { code: 'non_connecte' } }));
    await expect(appelApi('/v1/moi')).rejects.toMatchObject({ code: 'non_connecte', statut: 401 });
    expect(refuse).toHaveBeenCalledTimes(1);
    quandJetonRefuse(null);
  });
});
