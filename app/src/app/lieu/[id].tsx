import { useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Circle, Path } from 'react-native-svg';

import { BoutonSuivre } from '@/components/bouton-suivre';
import { ListeDates } from '@/components/liste-dates';
import { Attente, BoutonRetour } from '@/components/page-attente';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { aDistance } from '@/fiche/fiche';
import { itineraire } from '@/fiche/itineraire';
import { chargerLieu } from '@/fiche/pages';
import { usePageDates } from '@/fiche/use-page-dates';

/** Page d'un lieu (F4.2, maquette « Page lieu ») : adresse, accès, ses spectacles à venir par date. */
export default function PageLieu() {
  const { id, lat, lon } = useLocalSearchParams<{ id: string; lat?: string; lon?: string }>();
  const position = lat && lon ? { lat: Number(lat), lon: Number(lon) } : null;
  const marges = useSafeAreaInsets();
  const { page, erreur, reessayer, surDefilement } = usePageDates((suivant) => chargerLieu(Number(id), position ?? undefined, suivant), `${id}-${lat}-${lon}`);

  if (!page) {
    return <Attente erreur={erreur} reessayer={reessayer} />;
  }

  const adresse = [page.adresse, [page.code_postal, page.ville].filter(Boolean).join(' ')].filter(Boolean).join(', ');
  const distance = aDistance(page.distance_m);

  return (
    <ScrollView style={styles.fond} contentContainerStyle={{ paddingBottom: 40 + marges.bottom }} onScroll={surDefilement} scrollEventThrottle={200}>
      <View style={[styles.entete, { paddingTop: 16 + marges.top }]}>
        <BoutonRetour clair />
        <View style={styles.enteteTextes}>
          <Text style={styles.surtitre}>Lieu</Text>
          <Text style={styles.titre} accessibilityRole="header">{page.nom}</Text>
          {adresse || distance ? (
            <Text style={styles.adresse}>
              {adresse || page.ville}
              {distance ? ` · ${page.position_approximative ? '≈ ' : ''}${distance}` : ''}
            </Text>
          ) : null}
        </View>
        <BoutonSuivre type="lieu" id={page.id} avecAide />
      </View>

      <View style={styles.actions}>
        {page.adresse || page.code_postal ? (
          <Action libelle="Itinéraire" onPress={() => itineraire(page)}>
            <Path d="M3 11l18-8-8 18-2-8z" />
          </Action>
        ) : null}
        {page.telephone ? (
          <Action libelle="Appeler" onPress={() => Linking.openURL(`tel:${page.telephone!.replace(/\s/g, '')}`)}>
            <Path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2" />
          </Action>
        ) : null}
        {page.site_web ? (
          <Action libelle="Site du lieu" onPress={() => WebBrowser.openBrowserAsync(page.site_web!)}>
            <Circle cx={12} cy={12} r={9} />
            <Path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
          </Action>
        ) : null}
      </View>

      <View style={styles.dates}>
        <Text style={styles.rubrique}>Prochains spectacles</Text>
        <ListeDates
          jours={page.jours}
          position={position}
          origine="page_lieu"
          suite={!!page.suivant}
          vide="Les spectacles de ce lieu apparaîtront ici dès qu’ils seront annoncés dans nos sources."
        />
      </View>
    </ScrollView>
  );
}

function Action({ libelle, onPress, children }: { libelle: string; onPress: () => void; children: React.ReactNode }) {
  return (
    <Pressable style={styles.action} onPress={onPress} accessibilityRole="button">
      <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke={Couleurs.encre} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        {children}
      </Svg>
      <Text style={styles.actionTexte}>{libelle}</Text>
    </Pressable>
  );
}

// Valeurs de la maquette « Page lieu ».
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { gap: 16, paddingHorizontal: Espaces.bord, paddingBottom: 22, backgroundColor: '#FCE9CF' },
  enteteTextes: { gap: 6 },
  surtitre: { fontFamily: Polices.texteTresGras, fontSize: 12, letterSpacing: 1.2, textTransform: 'uppercase', color: '#7A3E00' },
  titre: { fontFamily: Polices.titreFort, fontSize: 32, lineHeight: 33, letterSpacing: -0.64, color: Couleurs.encre },
  adresse: { fontFamily: Polices.texte, fontSize: 14, lineHeight: 20, color: '#4A4A55' },
  actions: { flexDirection: 'row', gap: 8, paddingTop: 16, paddingHorizontal: Espaces.bord },
  action: {
    flex: 1, height: 64, borderRadius: Espaces.rayonCarte, borderWidth: 1, borderColor: Couleurs.traitCarte, backgroundColor: Couleurs.carte,
    alignItems: 'center', justifyContent: 'center', gap: 4,
  },
  actionTexte: { fontFamily: Polices.texteGras, fontSize: 12, color: Couleurs.encre },
  dates: { gap: 12, paddingTop: 22, paddingHorizontal: Espaces.bord },
  rubrique: { fontFamily: Polices.titre, fontSize: 22, color: Couleurs.encre },
});
