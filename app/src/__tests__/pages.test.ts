/// <reference types="jest" />
import { ajouterJours, type CarteRecherche, libelleDate, versCarte } from '@/fiche/pages';

const carte = (champs: Partial<CarteRecherche>): CarteRecherche => ({
  spectacle: { id: 1, titre: 'Lesbien Tomber', classification: 'Comédie', genre_id: 1, jeune_public: false },
  lieu: { id: 2, nom: 'Laurette Théâtre', position_approximative: false, ville: 'Avignon' },
  distance_m: 600, date_locale: '2026-10-17', date_fin: null, type: 'seance', seances: [], prix_min: 15, badges: [], gratuit: false,
  ...champs,
});

describe('pages lieu et artiste (F4.2)', () => {
  it('affiche une séance avec son talon, une période avec sa mention', () => {
    const seance = versCarte(carte({ seances: [{ representation_id: 9, debut: '2026-10-17T19:00:00+02:00', complet: false }] }));
    expect('horaire' in seance && seance.horaire.seances[0].representation_id).toBe(9);

    const periode = versCarte(carte({ type: 'periode', date_fin: '2026-12-18', representation_id: 7 }));
    expect('journee' in periode && periode.journee).toMatchObject({ type: 'periode', representation_id: 7, date_fin: '2026-12-18' });
  });

  it('raccorde le jour à cheval entre deux pages', () => {
    const a = carte({});
    const b = carte({ spectacle: { ...a.spectacle, id: 3 } });
    expect(ajouterJours([{ date: '2026-10-17', cartes: [a] }], [{ date: '2026-10-17', cartes: [b] }, { date: '2026-10-18', cartes: [a] }])).toEqual([
      { date: '2026-10-17', cartes: [a, b] },
      { date: '2026-10-18', cartes: [a] },
    ]);
  });
});

describe('autres dates (F5.3)', () => {
  it('écrit chaque date clairement', () => {
    expect(libelleDate({ representation_id: 1, date_locale: '2026-10-17', date_fin: null, type: 'seance', debut: '2026-10-17T19:30:00+02:00', complet: false })).toBe('Sam. 17 oct. · 19:30');
    expect(libelleDate({ representation_id: 2, date_locale: '2026-10-01', date_fin: '2026-12-18', type: 'periode', debut: null, complet: false })).toBe('Du 1er oct. au 18 déc.');
    expect(libelleDate({ representation_id: 3, date_locale: '2026-10-17', date_fin: null, type: 'jour', debut: null, complet: false })).toBe('Sam. 17 oct. · horaire à confirmer');
  });
});
