/// <reference types="jest" />
import { etapeAttente, ilYA, messageVide, moment, programmeAncien, type ReponseAutour } from '@/autour/autour';

const reponse = (champs: Partial<ReponseAutour>): ReponseAutour => ({
  rayon_retenu_m: 50000,
  couverture_faible: false,
  suggestion_date: null,
  total_tous_genres: null,
  programme_mis_a_jour_le: null,
  total: 0,
  cartes: [],
  toute_la_journee: [],
  suivant: null,
  ...champs,
});

describe('résultats vides (F2.7)', () => {
  it('propose tous les genres quand le filtre est trop restrictif', () => {
    expect(messageVide(reponse({ total_tous_genres: 12 }), 'ce_soir', 'Danse')).toEqual({
      cas: 'genre',
      texte: 'Pas de danse ce soir près d’ici.',
      action: 'Voir tous les genres (12)',
    });
  });

  it('propose une autre date quand la fenêtre est vide', () => {
    expect(messageVide(reponse({ suggestion_date: { quand: 'week_end', nombre: 4 } }), 'ce_soir', null)).toEqual({
      cas: 'date',
      texte: 'Rien ce soir près d’ici.',
      action: '4 spectacles ce week-end → Voir',
      quand: 'week_end',
    });
  });

  it('explique toujours, même quand il n’y a rien du tout', () => {
    expect(messageVide(reponse({}), '2026-10-17', null)).toEqual({ cas: 'rien', texte: 'Aucun spectacle le 17 octobre à moins de 50 km.' });
    expect(messageVide(reponse({ total: 3 }), 'ce_soir', null)).toBeNull();
  });

  it('écrit la fenêtre dans une phrase', () => {
    expect(moment('demain')).toBe('demain');
    expect(moment('2026-11-01')).toBe('le 1er novembre');
  });
});

describe('messages à l’utilisateur (F2.8)', () => {
  const maintenant = new Date('2026-10-11T18:00:00+02:00');

  it('prévient quand le programme a plus de 36 h', () => {
    expect(programmeAncien('2026-10-10T08:00:00+02:00', maintenant)).toBeNull(); // 34 h
    expect(programmeAncien('2026-10-09T18:00:00+02:00', maintenant)).toBe('Programme mis à jour il y a 2 jours. Pensez à vérifier auprès de la salle.');
    expect(programmeAncien(null, maintenant)).toBeNull();
  });

  it('dit depuis quand la liste n’a pas été mise à jour', () => {
    const t = maintenant.getTime();
    expect(ilYA(t - 20_000, t)).toBe('à l’instant');
    expect(ilYA(t - 5 * 60_000, t)).toBe('il y a 5 min');
    expect(ilYA(t - 2 * 3_600_000, t)).toBe('il y a 2 h');
    expect(ilYA(t - 3 * 86_400_000, t)).toBe('il y a 3 jours');
  });

  it('suit les délais du BO pendant un chargement', () => {
    expect(etapeAttente(1)).toBe('squelettes');
    expect(etapeAttente(3)).toBe('lent');
    expect(etapeAttente(9)).toBe('tres_lent');
    expect(etapeAttente(20)).toBe('echec');
    expect(etapeAttente(6, [2, 5, 10])).toBe('tres_lent');
  });
});
