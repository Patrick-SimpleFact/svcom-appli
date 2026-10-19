/// <reference types="jest" />
import { chaineParametres } from '@/api/client';
import { ecartMetres, enFrance, libelleJour, parametresAutour, titreAccueil } from '@/autour/autour';
import { DUREE_VILLE_MS, lireVille } from '@/autour/ville';

const base = { point: { lat: 43.95, lon: 4.81 }, quand: 'ce_soir', rayon_m: null, gouts: [1, 3] };
const url = (p: Parameters<typeof parametresAutour>[0]) => chaineParametres(parametresAutour(p));

describe('recherche « autour de moi »', () => {
  it('envoie la bonne fenêtre et le bon onglet à l’API', () => {
    expect(url({ ...base, onglet: 'tout' })).toBe('?lat=43.95&lon=4.81&quand=ce_soir&rayon=auto&tri=heure');
    expect(url({ ...base, onglet: 5, quand: 'week_end' })).toBe('?lat=43.95&lon=4.81&quand=week_end&rayon=auto&tri=heure&genres%5B%5D=5');
    expect(url({ ...base, onglet: 'pour_vous', rayon_m: 10000 })).toBe('?lat=43.95&lon=4.81&quand=ce_soir&rayon=10000&tri=heure&pour_vous=1&gouts%5B%5D=1&gouts%5B%5D=3');
    expect(url({ ...base, onglet: 'jeune_public' })).toContain('&jeune_public=1');
    expect(url({ ...base, point: { ville_id: 12329 }, onglet: 'tout', quand: '2026-10-17' })).toBe('?ville_id=12329&quand=2026-10-17&rayon=auto&tri=heure');
    expect(url({ ...base, onglet: 'tout', tri: 'distance' })).toBe('?lat=43.95&lon=4.81&quand=ce_soir&rayon=auto&tri=distance');
  });

  it('donne le titre de chaque puce', () => {
    const soir = new Date(2026, 9, 11, 18, 0);
    expect(titreAccueil(soir, 'demain')).toBe('Demain,\nprès de vous');
    expect(titreAccueil(soir, 'week_end')).toBe('Ce week-end,\nprès de vous');
    expect(titreAccueil(soir, '2026-10-17')).toBe('Samedi 17 octobre,\nprès de vous');
    expect(titreAccueil(soir, '2026-11-01')).toBe('Dimanche 1er novembre,\nprès de vous');
    expect(libelleJour('2026-10-17')).toBe('Sam. 17 oct.');
  });

  it('couvre la métropole et l’outre-mer', () => {
    expect(enFrance('FR')).toBe(true);
    expect(enFrance('re')).toBe(true);
    expect(enFrance('GP')).toBe(true);
    expect(enFrance('BE')).toBe(false);
    expect(enFrance(null)).toBe(true); // pays inconnu : on ne bloque pas
  });

  it('mesure un déplacement', () => {
    const avignon = { lat: 43.9493, lon: 4.8055 };
    expect(ecartMetres(avignon, avignon)).toBe(0);
    expect(ecartMetres(avignon, { lat: 43.9583, lon: 4.8055 })).toBeGreaterThan(990); // ≈ 1 km plus au nord
    expect(ecartMetres(avignon, { lat: 43.9583, lon: 4.8055 })).toBeLessThan(1010);
  });
});

describe('ville choisie', () => {
  const json = (choisieLe: number) => JSON.stringify({ ville: { id: 12329, nom: 'Bordeaux' }, choisie_le: choisieLe });

  it('reste active 12 h', () => {
    const maintenant = Date.UTC(2026, 9, 11, 18);
    expect(lireVille(json(maintenant - 60_000), maintenant)).toEqual({ id: 12329, nom: 'Bordeaux' });
    expect(lireVille(json(maintenant - DUREE_VILLE_MS - 1), maintenant)).toBeNull();
    expect(lireVille(null, maintenant)).toBeNull();
    expect(lireVille('pas du JSON', maintenant)).toBeNull();
  });
});
