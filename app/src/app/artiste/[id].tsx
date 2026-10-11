import { Image } from 'expo-image';
import { useLocalSearchParams } from 'expo-router';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ListeDates } from '@/components/liste-dates';
import { Attente, BoutonRetour } from '@/components/page-attente';
import { Couleurs, Espaces, Polices } from '@/constants/theme';
import { chargerArtiste } from '@/fiche/pages';
import { usePageDates } from '@/fiche/use-page-dates';

/** Page d'un artiste (F4.2, maquette « Page artiste ») : son nom, ses dates à venir, les plus proches d'abord. */
export default function PageArtiste() {
  const { id, lat, lon } = useLocalSearchParams<{ id: string; lat?: string; lon?: string }>();
  const position = lat && lon ? { lat: Number(lat), lon: Number(lon) } : null;
  const marges = useSafeAreaInsets();
  const { page, erreur, reessayer, surDefilement } = usePageDates((suivant) => chargerArtiste(Number(id), position ?? undefined, suivant), `${id}-${lat}-${lon}`);

  if (!page) {
    return <Attente erreur={erreur} reessayer={reessayer} />;
  }

  return (
    <ScrollView style={styles.fond} contentContainerStyle={{ paddingBottom: 40 + marges.bottom }} onScroll={surDefilement} scrollEventThrottle={200}>
      <View style={[styles.entete, { height: 250 + marges.top, paddingTop: 16 + marges.top }]}>
        {page.image_url ? (
          <>
            <Image source={page.image_url} style={StyleSheet.absoluteFill} contentFit="cover" accessibilityLabel={`Photo de ${page.nom}`} />
            <View style={[StyleSheet.absoluteFill, styles.voile]} />
          </>
        ) : null}
        <BoutonRetour />
        <View style={styles.enteteTextes}>
          <Text style={styles.etiquette}>Artiste</Text>
          <Text style={styles.titre} accessibilityRole="header">{page.nom}</Text>
        </View>
      </View>

      <View style={styles.dates}>
        <View style={styles.datesEntete}>
          <Text style={styles.rubrique}>Dates à venir</Text>
          {position ? <Text style={styles.ordre}>les plus proches d’abord</Text> : null}
        </View>
        <ListeDates
          jours={page.jours}
          position={position}
          origine="page_artiste"
          suite={!!page.suivant}
          vide="Ses prochaines dates apparaîtront ici dès qu’elles seront annoncées dans nos sources."
        />
      </View>
    </ScrollView>
  );
}

// Valeurs de la maquette « Page artiste ».
const styles = StyleSheet.create({
  fond: { flex: 1, backgroundColor: Couleurs.fond },
  entete: { justifyContent: 'space-between', paddingHorizontal: Espaces.bord, paddingBottom: 22, overflow: 'hidden', backgroundColor: '#2A2A33' },
  voile: { backgroundColor: 'rgba(0,0,0,0.45)' },
  enteteTextes: { gap: 6 },
  etiquette: {
    alignSelf: 'flex-start', overflow: 'hidden', paddingVertical: 4, paddingHorizontal: 10, borderRadius: 12, backgroundColor: '#E3EAFB',
    fontFamily: Polices.texteTresGras, fontSize: 11, letterSpacing: 0.9, textTransform: 'uppercase', color: '#1E3A8A',
  },
  titre: { fontFamily: Polices.titreFort, fontSize: 40, lineHeight: 40, letterSpacing: -1.2, color: '#FFFFFF' },
  dates: { gap: 10, paddingTop: 22, paddingHorizontal: Espaces.bord },
  datesEntete: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between' },
  rubrique: { fontFamily: Polices.titre, fontSize: 22, color: Couleurs.encre },
  ordre: { fontFamily: Polices.texte, fontSize: 12, color: Couleurs.gris },
});
