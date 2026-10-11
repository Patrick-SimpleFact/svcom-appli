/// <reference types="jest" />
import { aDistance, type Billetterie, duree, type Fiche, jourLong, libellePrix, lienSortie, reservation, type Seance, texteBouton, trajet } from '@/fiche/fiche';

const billet = (source: string, champs: Partial<Billetterie> = {}): Billetterie => ({
  offre_id: Math.floor(Math.random() * 1e6), source, billetterie: true, prix_min: 15, prix_max: null, complet: false, recommandee: false,
  lien_sortie: `https://spettacoli.test/sortie/1?a=empreinte`, ...champs,
});
const seance = (debut: string, billetteries: Billetterie[], complet = false): Seance => ({
  representation_id: Number(debut.slice(11, 13)), type: 'seance', debut: `2026-10-17T${debut.slice(11)}:00+02:00`, date_fin: null, salle: null, complet, billetteries,
});
const fiche = (seances: Seance[], prix: Partial<Fiche['prix']> = {}, termine = false): Fiche => ({
  id: 1, titre: 'La mort c’est mieux à deux', image_url: null, genre: null, classification: 'Comédie', jeune_public: false, age_min: null, duree_minutes: null,
  festival: null, description: null, artistes: [], termine, lieu: null, jour: '2026-10-17', seances, prix: { min: 15, max: 24, gratuit: false, ...prix },
  autres_dates: 0, sources: [], mentions: [], mis_a_jour_le: null,
});

describe('fiche spectacle (F5.2)', () => {
  it('met en forme le jour, la durée, le prix et la distance', () => {
    expect(jourLong('2026-10-03')).toBe('Samedi 3 octobre');
    expect(duree(80)).toBe('1 h 20');
    expect(duree(45)).toBe('45 min');
    expect(duree(120)).toBe('2 h');
    expect(libellePrix({ min: 15, max: 24, gratuit: false })).toBe('De 15 € à 24 €');
    expect(libellePrix({ min: 12.5, max: null, gratuit: false })).toBe('12,50 €');
    expect(libellePrix({ min: null, max: null, gratuit: true })).toBe('Gratuit');
    expect(libellePrix({ min: null, max: null, gratuit: false })).toBe('Tarifs sur la billetterie'); // jamais de prix inventé
    expect(aDistance(880)).toBe('à 900 m');
    expect(trajet(960)).toBe('12 min à pied');
    expect(trajet(12_400)).toBe('à 12 km');
  });
});

describe('réserver (F5.4)', () => {
  it('met en avant la billetterie recommandée par le BO et propose les autres', () => {
    const br = billet('BilletRéduc', { recommandee: true });
    const fnac = billet('Fnac Spectacles', { prix_min: 18 });
    const r = reservation(fiche([seance('2026-10-17T19:30', [br, fnac])]), seance('2026-10-17T19:30', [br, fnac]));
    expect(r).toEqual({ cas: 'reserver', principale: br, autres: [fnac] });
    expect(texteBouton(br)).toBe('Réserver sur BilletRéduc');
  });

  it('renvoie vers l’organisateur quand il n’y a pas de billetterie', () => {
    const da = billet('DATAtourisme', { billetterie: false, prix_min: null });
    const s = seance('2026-10-17T20:30', [da]);
    expect(reservation(fiche([s]), s)).toEqual({ cas: 'reserver', principale: da, autres: [] });
    expect(texteBouton(da)).toBe('Voir sur le site de l’organisateur');
  });

  it('annonce « Entrée libre » pour un spectacle gratuit sans billetterie', () => {
    const oa = billet('OpenAgenda', { billetterie: false, prix_min: null });
    const s = seance('2026-10-17T18:00', [oa]);
    expect(reservation(fiche([s], { min: null, max: null, gratuit: true }), s)).toEqual({ cas: 'entree_libre', organisateur: oa });
  });

  it('désactive le bouton d’une séance complète et propose celle qui a encore des places', () => {
    const tot = seance('2026-10-17T19:30', [billet('BilletRéduc')]);
    const tard = seance('2026-10-17T21:15', [billet('BilletRéduc', { complet: true })], true);
    expect(reservation(fiche([tot, tard]), tard)).toEqual({ cas: 'complet', autreSeance: tot });
  });

  it('dit qu’un spectacle est terminé', () => {
    expect(reservation(fiche([], {}, true), undefined)).toEqual({ cas: 'termine' });
  });

  it('ajoute l’origine du clic au lien de sortie, sans toucher à l’empreinte', () => {
    expect(lienSortie(billet('BilletRéduc'), 'liste_ce_soir', 'principal', 880)).toBe(
      'https://spettacoli.test/sortie/1?a=empreinte&origine=liste_ce_soir&bouton=principal&distance_km=0.9',
    );
  });
});
