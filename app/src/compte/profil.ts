import { appelApi } from '@/api/client';
import type { Gouts } from '@/gouts/gouts';

/** Préférences du compte (API §8) : goûts retrouvés sur tous les appareils, alertes et rappel du jour J (F3). */
export type Preferences = {
  genres: number[];
  rayon_m: number | null;
  alertes_actives: boolean;
  rappel_jour_j: boolean;
};

export function lirePreferences(): Promise<Preferences> {
  return appelApi<Preferences>('/v1/moi/preferences');
}

export function modifierPreferences(champs: Partial<Preferences>): Promise<Preferences> {
  return appelApi<Preferences>('/v1/moi/preferences', { methode: 'PUT', corps: champs });
}

/** Nombre de lieux et d'artistes suivis (Profil › Mes goûts) ; la liste arrive en M12. */
export async function nombreSuivis(): Promise<number> {
  const r = await appelApi<{ suivis: unknown[] }>('/v1/moi/suivis');

  return r.suivis.length;
}

/** « Recevoir mes données » (F1.7) : export envoyé par e-mail. */
export function exporterMesDonnees(): Promise<unknown> {
  return appelApi('/v1/moi/export', { methode: 'POST' });
}

/** Suppression du compte (F1.7) : accès coupé tout de suite, données effacées sous 30 jours. */
export function supprimerMonCompte(): Promise<unknown> {
  return appelApi('/v1/moi', { methode: 'DELETE' });
}

/** Suggestion à l'ouverture (F6) : acceptée (`oui`) ou coupée (`desactive`) depuis le Profil. */
export function choisirSuggestion(choix: 'oui' | 'desactive'): Promise<unknown> {
  return appelApi('/v1/appareils/suggestion', { methode: 'PUT', corps: { choix } });
}

/** Les goûts du téléphone au format des préférences du compte. */
export function versPreferences(gouts: Gouts): Pick<Preferences, 'genres' | 'rayon_m'> {
  return { genres: gouts.genres, rayon_m: gouts.rayon_m };
}

/** « Théâtre, Humour », « Aucun » ; au-delà de deux genres : « Théâtre, Humour +2 ». */
export function resumeGenres(ids: number[], libelle: (id: number) => string): string {
  const noms = ids.map(libelle).filter(Boolean);
  if (!noms.length) {
    return 'Aucun';
  }

  return noms.length > 2 ? `${noms.slice(0, 2).join(', ')} +${noms.length - 2}` : noms.join(', ');
}

/** « Connexion par e-mail · naissance : 03/1988 » (carte du compte, maquette « Profil »). */
export function ligneCompte(methodes: string[], mois: number | null, annee: number | null): string {
  const noms: Record<string, string> = { email: 'e-mail', apple: 'Apple', google: 'Google' };
  // `methodes` ne liste que les connexions Apple et Google : un compte créé par code e-mail n'en a aucune.
  const methode = `Connexion par ${methodes.length ? methodes.map((m) => noms[m] ?? m).join(' ou ') : 'e-mail'}`;
  const naissance = mois && annee ? `naissance : ${String(mois).padStart(2, '0')}/${annee}` : null;

  return [methode, naissance].filter(Boolean).join(' · ');
}
