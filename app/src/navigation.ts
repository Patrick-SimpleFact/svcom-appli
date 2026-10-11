import { router } from 'expo-router';

/**
 * « Fermer » / « Retour » des écrans ouverts par-dessus les onglets : revient à l'écran précédent,
 * ou à l'accueil quand il n'y en a pas (écran rouvert seul après un rechargement, lien direct…).
 */
export function revenir() {
  if (router.canGoBack()) {
    router.back();
  } else {
    router.replace('/');
  }
}
