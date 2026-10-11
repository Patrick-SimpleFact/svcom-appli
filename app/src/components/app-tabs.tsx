import { NativeTabs } from 'expo-router/unstable-native-tabs';

import { Couleurs } from '@/constants/theme';

/** Barre à 4 onglets des maquettes (Autour de moi, Rechercher, Favoris, Profil), native sur iPhone et Android. */
export default function AppTabs() {
  return (
    <NativeTabs
      backgroundColor={Couleurs.carte}
      tintColor={Couleurs.rouge}
      iconColor={{ default: Couleurs.gris, selected: Couleurs.rouge }}
      labelStyle={{ color: Couleurs.gris }}>
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
      </NativeTabs.Trigger>
      <NativeTabs.Trigger name="profil">
        <NativeTabs.Trigger.Label>Profil</NativeTabs.Trigger.Label>
        <NativeTabs.Trigger.Icon sf={{ default: 'person', selected: 'person.fill' }} md="person" />
      </NativeTabs.Trigger>
    </NativeTabs>
  );
}
