import { Platform } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

/**
 * Marge haute d'une modale `presentationStyle="pageSheet"`. Sur iPhone, c'est une carte posée sous la barre d'état ;
 * sur Android, elle occupe tout l'écran, barre d'état comprise (affichage bord à bord) : on décale son contenu.
 */
export function useHautFeuille(): number {
  const { top } = useSafeAreaInsets();
  return Platform.OS === 'android' ? top : 0;
}
