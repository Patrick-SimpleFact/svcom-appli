/// <reference types="jest" />
import { chaineParametres } from '@/api/client';
import { type Carte, type CarteJournee, compteur, distance, estComplete, mentionJournee, talon, titreAccueil } from '@/autour/autour';
import { libelleLieu } from '@/autour/position';

jest.mock('expo-location', () => ({}));

const carte = (seances: [string, boolean][], badges: Carte['badges'] = []): Carte => ({
  spectacle: { id: 1, titre: 'La mort c’est mieux à deux', classification: 'Comédie', genre_id: 1, jeune_public: false },
  lieu: { id: 2, nom: 'Théâtre de l’Observance', position_approximative: false },
  distance_m: 900,
  date_locale: '2026-10-17',
  type: 'seance',
  seances: seances.map(([debut, complet], i) => ({ representation_id: i, debut, complet })),
  badges,
  gratuit: false,
});

const journee = (type: CarteJournee['type'], dateFin: string | null, fin: string | null): CarteJournee => ({
  ...carte([]),
  representation_id: 9,
  type,
  date_locale: '2026-10-01',
  date_fin: dateFin,
  fin,
});

describe('cartes « autour de moi »', () => {
  it('affiche l’heure du lieu et les séances suivantes', () => {
    expect(talon(carte([['2026-10-17T19:30:00+02:00', false], ['2026-10-17T21:15:00+02:00', false]]))).toEqual({ heure: '19:30', suite: '+ 21:15' });
    expect(talon(carte([['2026-10-17T20:30:00-04:00', false]]))).toEqual({ heure: '20:30', suite: '' }); // outre-mer : heure du lieu
  });

  it('écrit les distances comme sur la maquette', () => {
    expect(distance(380)).toBe('400 m');
    expect(distance(10)).toBe('50 m');
    expect(distance(1240)).toBe('1,2 km');
    expect(distance(12400)).toBe('12 km');
    expect(distance(900, true)).toBe('≈ 900 m');
  });

  it('compte les spectacles et le rayon retenu', () => {
    expect(compteur(12, 5000)).toBe('12\u00a0spectacles à moins de 5\u00a0km');
    expect(compteur(1, 50000)).toBe('1\u00a0spectacle à moins de 50\u00a0km');
  });

  it('une carte est complète quand toutes ses séances le sont', () => {
    expect(estComplete(carte([['2026-10-17T19:30:00+02:00', true], ['2026-10-17T21:15:00+02:00', false]]))).toBe(false);
    expect(estComplete(carte([['2026-10-17T19:30:00+02:00', true]]))).toBe(true);
    expect(estComplete(carte([['2026-10-17T19:30:00+02:00', false]], ['complet']))).toBe(true);
  });

  it('donne toujours une mention aux spectacles sans horaire précis', () => {
    expect(mentionJournee(journee('jour', null, null))).toBe('Horaire à confirmer auprès du lieu');
    expect(mentionJournee(journee('periode', '2026-12-29', null))).toBe('Du 1er oct. au 29 déc. · horaires à confirmer');
    expect(mentionJournee(journee('continu', null, '2026-10-17T18:00:00+02:00'))).toBe('En continu · jusqu’à 18 h');
    expect(mentionJournee(journee('continu', null, '2026-10-17T18:30:00+02:00'))).toBe('En continu · jusqu’à 18 h 30');
    expect(mentionJournee(journee('continu', null, null))).toBe('En continu · horaires à confirmer');
  });

  it('dit « Aujourd’hui » avant 16 h, « Ce soir » ensuite', () => {
    expect(titreAccueil(new Date(2026, 9, 17, 15, 59))).toBe('Aujourd’hui,\nprès de vous');
    expect(titreAccueil(new Date(2026, 9, 17, 16, 0))).toBe('Ce soir,\nprès de vous');
  });
});

describe('position', () => {
  it('nomme la commune et le quartier', () => {
    expect(libelleLieu({ city: 'Avignon', district: 'Centre', subregion: 'Vaucluse' })).toBe('Avignon · Centre');
    expect(libelleLieu({ city: 'Figeac', district: null, subregion: 'Lot' })).toBe('Figeac');
    expect(libelleLieu({ city: null, district: null, subregion: null })).toBeNull();
    expect(libelleLieu(undefined)).toBeNull();
  });
});

describe('paramètres d’URL', () => {
  it('suit le format attendu par l’API', () => {
    expect(chaineParametres({ lat: 43.9, lon: 4.8, quand: 'ce_soir', rayon: 'auto', suivant: null })).toBe('?lat=43.9&lon=4.8&quand=ce_soir&rayon=auto');
    expect(chaineParametres({ gouts: [1, 3] })).toBe('?gouts%5B%5D=1&gouts%5B%5D=3');
    expect(chaineParametres({})).toBe('');
  });
});
