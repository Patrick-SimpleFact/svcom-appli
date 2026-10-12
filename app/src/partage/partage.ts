import { Share } from 'react-native';

import { dateDuJour, heure } from '@/autour/autour';
import type { Fiche, Seance } from '@/fiche/fiche';
import { mesurer } from '@/mesure/mesure';

const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

/** « 17/10 » */
function jourMois(jour: string): string {
  const [, m, j] = jour.split('-');

  return `${j}/${m}`;
}

/** « 19 h », « 20 h 30 ». */
function heureParlee(iso: string): string {
  const [h, m] = heure(iso).split(':');

  return m === '00' ? `${Number(h)} h` : `${Number(h)} h ${m}`;
}

/** Quand, en clair : « samedi 17/10 à 19 h », « samedi 17/10 », « du 17/10 au 25/10 ». */
export function quandEnClair(s: Seance): string {
  const jour = s.debut.slice(0, 10);
  const debut = `${JOURS[dateDuJour(jour).getDay()]} ${jourMois(jour)}`;

  if (s.type === 'seance') {
    return `${debut} à ${heureParlee(s.debut)}`;
  }
  if (s.date_fin && s.date_fin.slice(0, 10) !== jour) {
    return `du ${jourMois(jour)} au ${jourMois(s.date_fin.slice(0, 10))}`;
  }

  return debut;
}

/** Texte prêt à envoyer (F5.5) : « Lesbien Tomber, samedi 17/10 à 19 h, Laurette Théâtre Avignon ». */
export function texteDePartage(fiche: Pick<Fiche, 'titre' | 'lieu'>, seance: Seance | undefined): string {
  const lieu = fiche.lieu;
  const ville = lieu?.ville && !lieu.nom.toLowerCase().includes(lieu.ville.toLowerCase()) ? ` ${lieu.ville}` : '';

  return [fiche.titre, seance ? quandEnClair(seance) : null, lieu ? `${lieu.nom}${ville}` : null].filter(Boolean).join(', ');
}

/** Feuille de partage du téléphone, avec le texte et le lien de la fiche (F5.5, F5.8). */
export async function partagerFiche(fiche: Pick<Fiche, 'id' | 'titre' | 'lieu' | 'lien_partage'>, seance: Seance | undefined) {
  const texte = texteDePartage(fiche, seance);
  // Le lien dans le message, et non à part : sinon « Copier » (iOS) ne garde que le texte. Les messageries en font un aperçu.
  try {
    const resultat = await Share.share({ message: `${texte}\n${fiche.lien_partage}` });
    if (resultat.action === Share.sharedAction) {
      mesurer('partage', { spectacle_id: fiche.id });
    }
  } catch {
    // Feuille de partage indisponible : rien à faire.
  }
}

/**
 * Lien reçu par l'app (F5.8) → écran à ouvrir. Lien web partagé `https://…/s/titre-12?r=34` (liens universels)
 * ou schéma de l'app `spettacoli://spectacle/12?representation=34` (bouton « Ouvrir l'app » de la page web) :
 * la fiche, comptée comme ouverte depuis un lien partagé. Tout autre lien est laissé tel quel.
 */
export function cheminDepuisLien(lien: string): string {
  const web = lien.match(/^https?:\/\/[^/]+\/s\/[^/?#]*?-?(\d+)(?:[?#].*)?$/);
  const app = lien.match(/^spettacoli:\/\/spectacle\/(\d+)(?:\?(.*))?$/);

  if (web) {
    const r = lien.match(/[?&]r=(\d+)/);

    return `/spectacle/${web[1]}?origine=lien_partage${r ? `&representation=${r[1]}` : ''}`;
  }
  if (app) {
    const r = (app[2] ?? '').match(/(?:^|&)representation=(\d+)/);

    return `/spectacle/${app[1]}?origine=lien_partage${r ? `&representation=${r[1]}` : ''}`;
  }

  return lien;
}
