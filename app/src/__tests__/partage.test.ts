/// <reference types="jest" />
import { Share } from 'react-native';

import type { Fiche, Seance } from '@/fiche/fiche';
import { _fileMesure, _reinitialiserMesure } from '@/mesure/mesure';
import { cheminDepuisLien, partagerFiche, texteDePartage } from '@/partage/partage';

jest.mock('@/api/appareil', () => ({ identifiantAppareil: () => Promise.resolve('appareil-de-test') }));

const seance = (debut: string, autres: Partial<Seance> = {}): Seance => ({
  representation_id: 34, type: 'seance', debut, date_fin: null, salle: null, complet: false, billetteries: [], ...autres,
});
const lieu = (nom: string, ville: string | null) => ({
  id: 1, nom, ville, adresse: null, code_postal: null, lat: null, lon: null, position_approximative: false, telephone: null, site_web: null, distance_m: null,
});
const fiche = { id: 12, titre: 'Lesbien Tomber', lieu: lieu('Laurette Théâtre', 'Avignon'), lien_partage: 'https://spettacoli.test/s/lesbien-tomber-12?r=34' } as Pick<Fiche, 'id' | 'titre' | 'lieu' | 'lien_partage'>;

describe('partager une fiche (F5.5)', () => {
  it('prépare le texte : titre, quand, lieu et ville', () => {
    expect(texteDePartage(fiche, seance('2026-10-17T19:00:00+02:00'))).toBe('Lesbien Tomber, samedi 17/10 à 19 h, Laurette Théâtre Avignon');
    expect(texteDePartage(fiche, seance('2026-10-17T20:30:00+02:00'))).toBe('Lesbien Tomber, samedi 17/10 à 20 h 30, Laurette Théâtre Avignon');
    expect(texteDePartage(fiche, seance('2026-10-17T00:00:00+02:00', { type: 'jour' }))).toBe('Lesbien Tomber, samedi 17/10, Laurette Théâtre Avignon');
    expect(texteDePartage(fiche, seance('2026-10-17T00:00:00+02:00', { type: 'periode', date_fin: '2026-10-25T00:00:00+02:00' }))).toBe(
      'Lesbien Tomber, du 17/10 au 25/10, Laurette Théâtre Avignon',
    );
  });

  it('ne répète pas la ville si elle est déjà dans le nom du lieu, et se passe de lieu ou de séance', () => {
    expect(texteDePartage({ ...fiche, lieu: lieu('Opéra Grand Avignon', 'Avignon') }, seance('2026-10-17T19:00:00+02:00'))).toBe(
      'Lesbien Tomber, samedi 17/10 à 19 h, Opéra Grand Avignon',
    );
    expect(texteDePartage({ ...fiche, lieu: null }, undefined)).toBe('Lesbien Tomber');
  });

  it('ouvre la feuille de partage avec le lien dans le message (« Copier » le garde), et compte le partage seulement s’il a eu lieu', async () => {
    _reinitialiserMesure();
    const partager = jest.spyOn(Share, 'share').mockResolvedValueOnce({ action: Share.dismissedAction }).mockResolvedValueOnce({ action: Share.sharedAction });

    await partagerFiche(fiche, seance('2026-10-17T19:00:00+02:00'));
    expect(partager).toHaveBeenCalledWith({ message: `Lesbien Tomber, samedi 17/10 à 19 h, Laurette Théâtre Avignon\n${fiche.lien_partage}` });
    expect(_fileMesure()).toEqual([]);

    await partagerFiche(fiche, seance('2026-10-17T19:00:00+02:00'));
    expect(_fileMesure().map((e) => [e.type, e.donnees])).toEqual([['partage', { spectacle_id: 12 }]]);
  });
});

describe('ouvrir un lien partagé (F5.8)', () => {
  it('ouvre la fiche depuis le lien web ou le schéma de l’app, avec la séance', () => {
    expect(cheminDepuisLien('https://spettacoli.test/s/lesbien-tomber-12?r=34')).toBe('/spectacle/12?origine=lien_partage&representation=34');
    expect(cheminDepuisLien('https://spettacoli.fr/s/les-2-freres-812')).toBe('/spectacle/812?origine=lien_partage');
    expect(cheminDepuisLien('spettacoli://spectacle/12?representation=34')).toBe('/spectacle/12?origine=lien_partage&representation=34');
    expect(cheminDepuisLien('spettacoli://spectacle/12')).toBe('/spectacle/12?origine=lien_partage');
  });

  it('laisse les autres liens tels quels', () => {
    expect(cheminDepuisLien('/favoris')).toBe('/favoris');
    expect(cheminDepuisLien('spettacoli://lieu/5')).toBe('spettacoli://lieu/5');
    expect(cheminDepuisLien('https://spettacoli.test/confidentialite')).toBe('https://spettacoli.test/confidentialite');
  });
});
