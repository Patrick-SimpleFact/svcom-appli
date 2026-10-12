import { cheminDepuisLien } from '@/partage/partage';

/**
 * Liens qui ouvrent l'app (F5.8, M16) : lien partagé ou bouton « Ouvrir l'app » de la page web → fiche du spectacle.
 * Ne doit jamais planter : un lien illisible ouvre simplement l'app.
 */
export function redirectSystemPath({ path }: { path: string; initial: boolean }): string {
  try {
    return cheminDepuisLien(path);
  } catch {
    return '/';
  }
}
