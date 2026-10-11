import { ActionSheetIOS, Linking, Platform } from 'react-native';

import type { Fiche } from '@/fiche/fiche';

/** Applications de cartes proposées pour l'itinéraire (F5.2) : Plans, Google Maps et Waze selon ce qui est installé. */
export async function itineraire(lieu: NonNullable<Fiche['lieu']>) {
  const adresse = [lieu.adresse, lieu.code_postal, lieu.ville].filter(Boolean).join(' ');
  // Position approximative : on envoie l'adresse texte, l'application de cartes la cherchera elle-même (F5.7).
  const cible = !lieu.position_approximative && lieu.lat !== null && lieu.lon !== null ? `${lieu.lat},${lieu.lon}` : encodeURIComponent(adresse || lieu.nom);
  const options = [
    { nom: 'Plans', url: `http://maps.apple.com/?daddr=${cible}` },
    { nom: 'Google Maps', url: `comgooglemaps://?daddr=${cible}`, test: 'comgooglemaps://' },
    { nom: 'Waze', url: lieu.position_approximative ? `waze://?q=${cible}&navigate=yes` : `waze://?ll=${cible}&navigate=yes`, test: 'waze://' },
  ];
  const disponibles: { nom: string; url: string }[] = [];
  for (const o of options) {
    if (!o.test || (await Linking.canOpenURL(o.test).catch(() => false))) {
      disponibles.push(o);
    }
  }
  if (Platform.OS !== 'ios' || disponibles.length === 1) {
    Linking.openURL(Platform.OS === 'ios' ? disponibles[0].url : `geo:0,0?q=${cible}`);
    return;
  }
  ActionSheetIOS.showActionSheetWithOptions({ title: 'Itinéraire avec…', options: [...disponibles.map((o) => o.nom), 'Annuler'], cancelButtonIndex: disponibles.length }, (i) => {
    if (i < disponibles.length) {
      Linking.openURL(disponibles[i].url);
    }
  });
}
