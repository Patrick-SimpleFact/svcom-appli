import { NativeTabs } from 'expo-router/unstable-native-tabs';

import { Couleurs } from '@/constants/theme';
import { useFavoris } from '@/favoris/favoris';

/** Barre à 4 onglets des maquettes (Autour de moi, Rechercher, Favoris, Profil), native sur iPhone et Android. */
export default function AppTabs() {
  // Badge de l'onglet Favoris : nouveautés de vos suivis pas encore vues (F3.4).
  const { nonVues } = useFavoris();

  return (
    <NativeTabs
      backgroundColor={Couleurs.carte}
      tintColor={Couleurs.rouge}
      iconColor={{ default: Couleurs.gris, selected: Couleurs.rouge }}
      labelStyle={{ color: Couleurs.gris }}
      // Android (Material) : pastille et effet de toucher lavande par défaut, libellé sous l'onglet actif seulement.
      indicatorColor={Couleurs.fond}
      rippleColor={Couleurs.trait}
      labelVisibilityMode="labeled">
      <NativeTabs.Trigger name="index">
        <NativeTabs.Trigger.Label>Autour de moi</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'safari', selected: 'safari.fill' }} md="explore" />
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="recherche">
        <NativeTabs.Trigger.Label>Rechercher</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf="magnifyingglass" md="search" />
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="favoris">
        <NativeTabs.Trigger.Label>Favoris</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'heart', selected: 'heart.fill' }} md="favorite" />
        {/* Le badge n'existe que s'il y a des nouveautés : sur iOS 27, `hidden` ou un badge vide laissent un « 0 » ou une pastille. */}
        {nonVues > 0 ? <NativeTabs.Trigger.Badge>{String(nonVues)}</NativeTabs.Trigger.Badge> : null}
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="profil">
        <NativeTabs.Trigger.Label>Profil</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'person', selected: 'person.fill' }} md="person" />
      </NativeTabs.Trigger>
    </NativeTabs>
  );
}
